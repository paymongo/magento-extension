<?php

declare(strict_types=1);

namespace PayMongo\Payment\Gateway;

use PayMongo\Payment\Helper\Config;
use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Order\PaymentReference;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;

/**
 * HTTP client for the PayMongo REST API (https://api.paymongo.com/v1).
 *
 * Provides methods to create and retrieve checkout sessions, process refunds,
 * and look up payment intents. All requests use Basic Auth with the store's
 * secret key.
 *
 * v1.1.0.0 additions:
 * - Every response logs the PayMongo x-request-id header so support tickets
 *   can be resolved faster (this is the first field PayMongo support asks for).
 * - createCheckoutSession() accepts a specific payment_method_type so each
 *   individual method (GCash, GrabPay, etc.) presents only itself at checkout.
 * - A PHP-currency guard is enforced before any checkout session is created;
 *   non-PHP carts receive a clear error rather than a silent failure.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PaymongoClient
{
    private const API_BASE_URL = 'https://api.paymongo.com/v1';

    /**
     * Fallback payment method types used for the all-in-one checkout method
     * when no individual PayMongo method is currently enabled to build the
     * type list from.
     */
    private const DEFAULT_PAYMENT_METHOD_TYPES = ['card', 'gcash', 'grab_pay', 'paymaya'];

    /**
     * Sent as metadata.plugin on every resource this module creates so PayMongo can
     * attribute the transaction to the Magento 2 plugin on their side. Both the key
     * and the value are agreed with PayMongo — do not change either without checking
     * with them first.
     */
    private const METADATA_PLUGIN = 'magento_v2';

    /** @var Config */
    private Config $config;

    /** @var Curl */
    private Curl $curl;

    /** @var Json */
    private Json $json;

    /** @var Logger */
    private Logger $logger;

    /** @var UrlInterface */
    private UrlInterface $urlBuilder;

    /** @var PaymentReference */
    private PaymentReference $paymentReference;

    /**
     * @param Config $config
     * @param Curl $curl
     * @param Json $json
     * @param Logger $logger
     * @param UrlInterface $urlBuilder
     * @param PaymentReference $paymentReference
     */
    public function __construct(
        Config $config,
        Curl $curl,
        Json $json,
        Logger $logger,
        UrlInterface $urlBuilder,
        PaymentReference $paymentReference
    ) {
        $this->config = $config;
        $this->curl = $curl;
        $this->json = $json;
        $this->logger = $logger;
        $this->urlBuilder = $urlBuilder;
        $this->paymentReference = $paymentReference;
    }

    /**
     * Create a PayMongo hosted checkout session for the given order.
     *
     * Enforces PHP-only currency: if the order currency is not PHP a
     * LocalizedException is thrown with a clear message rather than letting
     * the PayMongo API return an opaque error.
     *
     * When $paymentMethodType is a single string (e.g. 'gcash') the session is
     * created with only that method so the customer lands directly on the
     * correct payment screen — used by the individual PayMongo methods. When
     * it's an array (the all-in-one checkout method), all of those types are
     * presented together on PayMongo's hosted page. When null or an empty
     * array, the fallback list is used.
     *
     * @param Order $order
     * @param string|array|null $paymentMethodType Specific PayMongo API method type, a list of
     *                                              types for the all-in-one checkout, or null for the fallback list
     * @param bool $authorizeOnly When true (cards only) the session uses capture_type=manual so the
     *                            card is authorised but not charged until the shipment is created
     * @return array Raw API response
     * @throws LocalizedException on non-PHP currency or API error
     */
    public function createCheckoutSession(Order $order, string|array|null $paymentMethodType = null, bool $authorizeOnly = false): array
    {
        $currency = $order->getOrderCurrencyCode() ?: $order->getBaseCurrencyCode();
        if ($currency !== 'PHP') {
            throw new LocalizedException(
                __('PayMongo only accepts PHP currency. This order is in %1.', $currency)
            );
        }

        // PayMongo rejects negative line_item.amount, so a discount can't be sent as its own
        // line. Magento already tracks each item's own discount_amount (stored positive), so
        // it's netted directly into that specific item's amount and stated in its name.
        $lineItems = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $qtyOrdered = (float) $item->getQtyOrdered();
            $rowCents = (int) round(((float) $item->getPriceInclTax()) * $qtyOrdered * 100);
            $itemDiscountCents = (int) round(abs((float) $item->getDiscountAmount()) * 100);
            $netRowCents = max(0, $rowCents - $itemDiscountCents);

            $name = $item->getName();
            if ($itemDiscountCents > 0) {
                $name .= sprintf(' (-₱%s)', number_format($itemDiscountCents / 100, 2));
            }

            // PayMongo charges amount × quantity, so the row total has to divide exactly by the
            // quantity or the customer is undercharged by the remainder. Decimal quantities
            // (is_qty_decimal products, sold by weight/length) can't be expressed at all — and
            // casting them to int previously yielded 0, making intdiv() raise DivisionByZeroError,
            // an \Error that the Redirect controller's catch blocks do not intercept.
            // In both cases fall back to a single line carrying the exact row total.
            $qtyInt = (int) round($qtyOrdered);
            $isWholeQty = abs($qtyOrdered - $qtyInt) < 0.00001 && $qtyInt >= 1;

            if ($isWholeQty && $netRowCents % $qtyInt === 0) {
                $quantity = $qtyInt;
                $amount = intdiv($netRowCents, $qtyInt);
            } else {
                $quantity = 1;
                $amount = $netRowCents;
                $name .= sprintf(' × %s', rtrim(rtrim(number_format($qtyOrdered, 4, '.', ''), '0'), '.'));
            }

            $lineItems[] = [
                'name' => $name,
                'quantity' => $quantity,
                'amount' => $amount,
                'currency' => 'PHP',
            ];
        }

        $shippingAmount = (float) $order->getShippingInclTax();
        if ($shippingAmount > 0) {
            $shippingCents = (int) round($shippingAmount * 100);
            $shippingDiscountCents = (int) round(abs((float) $order->getShippingDiscountAmount()) * 100);
            $netShippingCents = max(0, $shippingCents - $shippingDiscountCents);

            $shippingName = 'Shipping - ' . $order->getShippingDescription();
            if ($shippingDiscountCents > 0) {
                $shippingName .= sprintf(' (-₱%s)', number_format($shippingDiscountCents / 100, 2));
            }

            $lineItems[] = [
                'name' => $shippingName,
                'quantity' => 1,
                'amount' => $netShippingCents,
                'currency' => 'PHP',
            ];
        }

        if (is_array($paymentMethodType)) {
            $methodTypes = $paymentMethodType !== [] ? array_values($paymentMethodType) : self::DEFAULT_PAYMENT_METHOD_TYPES;
        } else {
            $methodTypes = $paymentMethodType ? [$paymentMethodType] : self::DEFAULT_PAYMENT_METHOD_TYPES;
        }

        $attributes = [
            'line_items' => $lineItems,
            'payment_method_types' => $methodTypes,
            'description' => $this->paymentReference->buildDescription($order),
            'send_email_receipt' => true,
            'show_description' => true,
            'show_line_items' => true,
            'reference_number' => $this->paymentReference->build($order),
            'success_url' => $this->urlBuilder->getUrl(
                'paymongo/payment/success',
                ['order_id' => $order->getId()]
            ),
            'cancel_url' => $this->urlBuilder->getUrl(
                'paymongo/payment/failed',
                ['order_id' => $order->getId()]
            ),
            'metadata' => $this->buildMetadata(),
        ];

        if ($authorizeOnly && $methodTypes === ['card']) {
            $attributes['capture_type'] = 'manual';
        }

        $payload = ['data' => ['attributes' => $attributes]];

        $this->logger->info('Creating PayMongo checkout session', [
            'order_id' => $order->getIncrementId(),
            'amount' => $order->getGrandTotal(),
            'method_type' => implode(',', $methodTypes),
        ]);

        $response = $this->makeRequest('/checkout_sessions', $payload, (int) $order->getStoreId());

        $this->logger->info('PayMongo checkout session created', [
            'order_id' => $order->getIncrementId(),
            'checkout_session_id' => $response['data']['id'] ?? 'unknown',
        ]);

        return $response;
    }

    /**
     * Build the metadata object sent with every resource this module creates.
     *
     * PayMongo uses metadata.plugin to attribute a transaction to the integration
     * that produced it. All values must be strings — PayMongo rejects other scalar
     * types in metadata.
     *
     * @return array<string, string>
     */
    private function buildMetadata(): array
    {
        return ['plugin' => self::METADATA_PLUGIN];
    }

    /**
     * Retrieve a checkout session by ID to check its status and extract payment details.
     *
     * @param string $sessionId PayMongo checkout session ID (cs_…)
     * @param int|null $storeId Store scope for API key resolution
     * @return array Raw API response
     */
    public function retrieveCheckoutSession(string $sessionId, ?int $storeId = null): array
    {
        $this->logger->info('Retrieving PayMongo checkout session', ['session_id' => $sessionId]);

        $url = self::API_BASE_URL . '/checkout_sessions/' . $sessionId;
        $this->curl->setHeaders($this->getAuthHeaders($storeId));
        $this->curl->get($url);

        $responseBody = $this->curl->getBody();
        $requestId = $this->extractRequestId();
        $response = $this->json->unserialize($responseBody);

        $this->logger->info('PayMongo checkout session retrieved', [
            'session_id' => $sessionId,
            'status' => $response['data']['attributes']['status'] ?? 'unknown',
            'paymongo_request_id' => $requestId,
        ]);

        return $response;
    }

    /**
     * Create a refund via the PayMongo API.
     *
     * @param string $paymentId PayMongo payment ID (pay_…)
     * @param int $amountCentavos Refund amount in centavos (minimum 100 = ₱1.00)
     * @param string $reason One of: requested_by_customer, duplicate, fraudulent, others
     * @param string|null $notes Optional internal notes (max 255 characters)
     * @param int|null $storeId Store scope for API key resolution
     * @return array Raw API response
     * @throws LocalizedException on API error
     */
    public function createRefund(
        string $paymentId,
        int $amountCentavos,
        string $reason = 'requested_by_customer',
        ?string $notes = null,
        ?int $storeId = null
    ): array {
        $payload = [
            'data' => [
                'attributes' => [
                    'payment_id' => $paymentId,
                    'amount' => max(100, $amountCentavos),
                    'reason' => $reason,
                    'metadata' => $this->buildMetadata(),
                ],
            ],
        ];

        if ($notes !== null && $notes !== '') {
            $payload['data']['attributes']['notes'] = substr($notes, 0, 255);
        }

        $this->logger->info('Creating PayMongo refund', [
            'payment_id' => $paymentId,
            'amount' => $amountCentavos / 100,
        ]);

        return $this->makeRequest('/refunds', $payload, $storeId);
    }

    /**
     * Capture a previously authorised payment intent.
     *
     * Called by the CaptureOnShipment observer when the card payment action is
     * set to 'authorize' and the merchant creates a shipment. The payment intent
     * must be in 'awaiting_capture' status.
     *
     * @param string $paymentIntentId PayMongo payment intent ID (pi_…)
     * @param int $amountCentavos Amount to capture in centavos; must equal the authorised amount
     * @param int|null $storeId Store scope for API key resolution
     * @return array Raw API response
     * @throws \Magento\Framework\Exception\LocalizedException on API error
     */
    public function capturePaymentIntent(string $paymentIntentId, int $amountCentavos, ?int $storeId = null): array
    {
        $payload = [
            'data' => [
                'attributes' => [
                    'amount' => $amountCentavos,
                ],
            ],
        ];

        $this->logger->info('Capturing PayMongo payment intent', [
            'payment_intent_id' => $paymentIntentId,
            'amount' => $amountCentavos / 100,
        ]);

        return $this->makeRequest(
            '/payment_intents/' . $paymentIntentId . '/capture',
            $payload,
            $storeId
        );
    }

    /**
     * Cancel a payment intent, releasing an authorisation hold without charging the customer.
     *
     * Called by the ReleaseHoldOnCancel observer when an Authorize Only order is cancelled
     * while PayMongo still holds the customer's funds. PayMongo takes no request body.
     *
     * @param string $paymentIntentId PayMongo payment intent ID (pi_…)
     * @param int|null $storeId Store scope for API key resolution
     * @return array Raw API response
     * @throws \Magento\Framework\Exception\LocalizedException on API error
     */
    public function cancelPaymentIntent(string $paymentIntentId, ?int $storeId = null): array
    {
        $this->logger->info('Cancelling PayMongo payment intent', [
            'payment_intent_id' => $paymentIntentId,
        ]);

        return $this->makeRequest('/payment_intents/' . $paymentIntentId . '/cancel', [], $storeId);
    }

    /**
     * Expire a checkout session, so its PayMongo payment page no longer accepts a payment.
     *
     * Called by the CloseCheckoutOnCancel observer when a PayMongo order is cancelled while the
     * customer could still pay on PayMongo's page, so no one is charged for a cancelled order
     * (PYM-32). PayMongo takes no request body.
     *
     * @param string $sessionId PayMongo checkout session ID (cs_…)
     * @param int|null $storeId Store scope for API key resolution
     * @return array Raw API response
     * @throws PaymongoApiException on API error
     */
    public function expireCheckoutSession(string $sessionId, ?int $storeId = null): array
    {
        $this->logger->info('Expiring PayMongo checkout session', [
            'checkout_session_id' => $sessionId,
        ]);

        return $this->makeRequest('/checkout_sessions/' . $sessionId . '/expire', [], $storeId);
    }

    /**
     * Fetch a page of payments from PayMongo for reconciliation.
     *
     * @param int $createdGte Unix timestamp — return payments created at or after this time
     * @param int $createdLte Unix timestamp — return payments created at or before this time
     * @param int|null $storeId Store scope for API key resolution
     * @return array Raw API response containing data[] list of payment objects
     * @throws \Magento\Framework\Exception\LocalizedException on API error
     */
    public function getPayments(int $createdGte, int $createdLte, ?int $storeId = null): array
    {
        $all   = [];
        // PayMongo lists newest first. "after" is the cursor to the next, older page;
        // "before" goes back to newer items, so paging with it never got past the newest page.
        $after = null;

        do {
            $query = 'limit=100' . ($after !== null ? '&after=' . urlencode($after) : '');
            $url   = self::API_BASE_URL . '/payments?' . $query;

            $this->curl->setHeaders($this->getAuthHeaders($storeId));
            $this->curl->get($url);

            $responseBody = $this->curl->getBody();
            $requestId    = $this->extractRequestId();
            $status       = $this->curl->getStatus();

            $this->logger->debug('PayMongo get payments', [
                'status'               => $status,
                'paymongo_request_id'  => $requestId,
            ]);

            if ($status < 200 || $status >= 300) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('PayMongo API error fetching payments: %1', $responseBody)
                );
            }

            $page   = $this->json->unserialize($responseBody)['data'] ?? [];
            $after  = null;
            $done   = false;

            foreach ($page as $item) {
                $createdAt = (int) ($item['attributes']['created_at'] ?? 0);
                if ($createdAt > $createdLte) {
                    continue;
                }
                if ($createdAt < $createdGte) {
                    $done = true;
                    break;
                }
                $all[] = $item;
            }

            if (!$done && count($page) === 100) {
                $after = end($page)['id'] ?? null;
            }
        } while ($after !== null);

        return ['data' => $all];
    }

    /**
     * Fetch a page of refunds from PayMongo for reconciliation.
     *
     * @param int $createdGte Unix timestamp — return refunds created at or after this time
     * @param int $createdLte Unix timestamp — return refunds created at or before this time
     * @param int|null $storeId Store scope for API key resolution
     * @return array Raw API response containing data[] list of refund objects
     * @throws \Magento\Framework\Exception\LocalizedException on API error
     */
    public function getRefunds(int $createdGte, int $createdLte, ?int $storeId = null): array
    {
        $all   = [];
        // PayMongo lists newest first. "after" is the cursor to the next, older page;
        // "before" goes back to newer items, so paging with it never got past the newest page.
        $after = null;

        do {
            $query = 'limit=100' . ($after !== null ? '&after=' . urlencode($after) : '');
            $url   = self::API_BASE_URL . '/refunds?' . $query;

            $this->curl->setHeaders($this->getAuthHeaders($storeId));
            $this->curl->get($url);

            $responseBody = $this->curl->getBody();
            $requestId    = $this->extractRequestId();
            $status       = $this->curl->getStatus();

            $this->logger->debug('PayMongo get refunds', [
                'status'               => $status,
                'paymongo_request_id'  => $requestId,
            ]);

            if ($status < 200 || $status >= 300) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('PayMongo API error fetching refunds: %1', $responseBody)
                );
            }

            $page   = $this->json->unserialize($responseBody)['data'] ?? [];
            $after  = null;
            $done   = false;

            foreach ($page as $item) {
                $createdAt = (int) ($item['attributes']['created_at'] ?? 0);
                if ($createdAt > $createdLte) {
                    continue;
                }
                if ($createdAt < $createdGte) {
                    $done = true;
                    break;
                }
                $all[] = $item;
            }

            if (!$done && count($page) === 100) {
                $after = end($page)['id'] ?? null;
            }
        } while ($after !== null);

        return ['data' => $all];
    }

    /**
     * Retrieve a payment intent by ID.
     *
     * Used as a fallback when only the payment intent ID is stored (not the payment ID),
     * for example when initiating a refund. The payment ID can be extracted from
     * the returned `data.attributes.payments[0].id` field.
     *
     * @param string $paymentIntentId PayMongo payment intent ID (pi_…)
     * @param int|null $storeId Store scope for API key resolution
     * @return array Raw API response
     * @throws LocalizedException on API error
     */
    public function retrievePaymentIntent(string $paymentIntentId, ?int $storeId = null): array
    {
        $url = self::API_BASE_URL . '/payment_intents/' . $paymentIntentId;
        $this->curl->setHeaders($this->getAuthHeaders($storeId));
        $this->curl->get($url);

        $responseBody = $this->curl->getBody();
        $requestId = $this->extractRequestId();
        $status = $this->curl->getStatus();

        if ($status < 200 || $status >= 300) {
            $this->logger->error('PayMongo retrieve payment intent error', [
                'id' => $paymentIntentId,
                'status' => $status,
                'paymongo_request_id' => $requestId,
                'response' => $responseBody,
            ]);
            throw new LocalizedException(__('PayMongo API error: %1', $responseBody));
        }

        return $this->json->unserialize($responseBody);
    }

    /**
     * Retrieve the payment method types actually active on this PayMongo account.
     *
     * This is an account-level activation status — separate from, and not implied
     * by, any of this module's own "Enabled" config toggles. A method can be fully
     * enabled in Magento and still fail in production if PayMongo hasn't activated
     * it on the account yet (methods like GrabPay typically require a separate
     * activation request to PayMongo). Used by the admin "Check PayMongo Account
     * Status" button on the Payment Methods config page.
     *
     * @param int|null $storeId Store scope for API key resolution
     * @return array<int, string> Active payment method type identifiers, e.g. ['card', 'qrph']
     * @throws LocalizedException on API error
     */
    public function getAccountPaymentMethodCapabilities(?int $storeId = null): array
    {
        $url = self::API_BASE_URL . '/merchants/capabilities/payment_methods';
        $this->curl->setHeaders($this->getAuthHeaders($storeId));
        $this->curl->get($url);

        $responseBody = $this->curl->getBody();
        $requestId = $this->extractRequestId();
        $status = $this->curl->getStatus();

        if ($status < 200 || $status >= 300) {
            $this->logger->error('PayMongo retrieve account capabilities error', [
                'status' => $status,
                'paymongo_request_id' => $requestId,
                'response' => $responseBody,
            ]);
            throw new LocalizedException(__('PayMongo API error: %1', $responseBody));
        }

        $decoded = $this->json->unserialize($responseBody);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Build the Authorization and content-type headers for a PayMongo API request.
     *
     * Uses HTTP Basic Auth with the secret key as the username and an empty password,
     * as required by the PayMongo API.
     *
     * @param int|null $storeId Store scope for API key resolution
     * @return array<string, string>
     */
    private function getAuthHeaders(?int $storeId = null): array
    {
        $secretKey = trim($this->config->getSecretKey($storeId));
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($secretKey . ':'),
        ];
    }

    /**
     * Extract the Paymongo-Request-Id from the last response headers.
     *
     * This is the field PayMongo support asks for first when a merchant files a
     * ticket. Logging it on every API call means support can correlate any logged
     * error directly to a specific request in PayMongo's system.
     *
     * @return string Empty string if the header was not present
     */
    private function extractRequestId(): string
    {
        $headers = $this->curl->getHeaders();
        return (string) ($headers['x-request-id'] ?? $headers['X-Request-Id'] ?? '');
    }

    /**
     * Execute a POST request against the PayMongo API.
     *
     * Logs the PayMongo request ID from the response headers on every call so
     * any API error can be cross-referenced with PayMongo support.
     *
     * @param string $endpoint API path relative to the base URL (e.g. '/checkout_sessions')
     * @param array $payload Request body that will be JSON-encoded; empty sends an empty JSON object
     * @param int|null $storeId Store scope for API key resolution
     * @return array Decoded response body
     * @throws PaymongoApiException on non-2xx HTTP status, carrying PayMongo's error detail
     */
    private function makeRequest(string $endpoint, array $payload, ?int $storeId = null): array
    {
        $url = self::API_BASE_URL . $endpoint;
        $this->curl->setHeaders($this->getAuthHeaders($storeId));
        $this->curl->post($url, $payload === [] ? '{}' : $this->json->serialize($payload));

        $responseBody = $this->curl->getBody();
        $requestId = $this->extractRequestId();
        $status = $this->curl->getStatus();

        $this->logger->debug('PayMongo API response', [
            'endpoint' => $endpoint,
            'status' => $status,
            'paymongo_request_id' => $requestId,
            'response' => $responseBody,
        ]);

        if ($status < 200 || $status >= 300) {
            $this->logger->error('PayMongo API error', [
                'endpoint' => $endpoint,
                'status' => $status,
                'paymongo_request_id' => $requestId,
                'response' => $responseBody,
            ]);
            throw PaymongoApiException::fromResponse((string) $responseBody);
        }

        return $this->json->unserialize($responseBody);
    }
}
