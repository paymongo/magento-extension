<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Order;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * Builds and parses the order reference PayMongo carries for each transaction.
 *
 * Everything the module sends to PayMongo that identifies an order — the checkout
 * session's reference_number, the description shown on the payment page, and the
 * notes on a refund — goes through build() or buildDescription(), so every one of
 * them is tagged with the same prefix and Magento transactions can be told apart in
 * the PayMongo dashboard.
 *
 * PREFIX is deliberately a constant and not an admin field. The tag is what the
 * merchant asked for and what their dashboard filters and exports are built around;
 * a store view quietly clearing it would break exactly the identification it exists
 * to provide.
 *
 * The reference is not only a label: PayMongo echoes it back in webhooks, and it is
 * how the module works out which Magento order a payment belongs to. So the reverse
 * direction lives here too — resolveIncrementId() undoes what build() and
 * buildDescription() did, and accepts nothing else. PayMongo delivers every payment on
 * the account to the webhook, including ones another system created, so a reference
 * this module did not send must never be read as a Magento order.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PaymentReference
{
    /**
     * Put in front of the order number in everything sent to PayMongo.
     *
     * Changing this strands every payment still in flight: resolveIncrementId() only
     * accepts the current prefix, so a webhook for a reference sent with the old one is
     * no longer matched to its order.
     */
    public const PREFIX = 'MGN-';

    /**
     * Opens the description, so a transaction reads as a Magento order in the PayMongo
     * dashboard without anyone having to know what the MGN- prefix stands for.
     */
    public const DESCRIPTION_LABEL = 'Magento Order #';

    /**
     * Build the reference PayMongo should carry for this order.
     *
     * Returns the order's increment ID with the prefix in front — e.g. "MGN-000000015".
     * The Magento order itself is untouched; this string exists only in what we send.
     *
     * @param OrderInterface|null $order
     * @return string Empty string when there is no order
     */
    public function build(?OrderInterface $order): string
    {
        if ($order === null) {
            return '';
        }

        $incrementId = (string) $order->getIncrementId();
        if ($incrementId === '') {
            return '';
        }

        return self::PREFIX . $incrementId;
    }

    /**
     * Build the description PayMongo should carry for this order.
     *
     * Reads "Magento Order #MGN-000000015": the source and the reference in one line,
     * which is what the PayMongo dashboard lists each payment by (PYM-22). show_description
     * is on, so the customer reads it on the PayMongo payment page too — which is why the
     * client chose readable wording over a bare code when the prefix was introduced
     * (PYM-13, 2026-09-10).
     *
     * It carries the full reference and not just the label because BPI Online and
     * UnionBank Online orders placed before they moved to Checkout Sessions (PYM-6) went
     * through a payment intent, which has no reference_number field — for those the
     * description is the only place the order is named, and the webhook that confirms
     * them is matched back to the order through it.
     *
     * @param OrderInterface|null $order
     * @return string Empty string when there is no order
     */
    public function buildDescription(?OrderInterface $order): string
    {
        $reference = $this->build($order);

        return $reference === '' ? '' : self::DESCRIPTION_LABEL . $reference;
    }

    /**
     * Build the notes PayMongo should carry on a refund of this order.
     *
     * Reads "Refund for Magento Order #MGN-000000015".
     *
     * @param OrderInterface|null $order
     * @return string|null Null when there is no order, so no dangling "Refund for " is sent
     */
    public function buildRefundNotes(?OrderInterface $order): ?string
    {
        $description = $this->buildDescription($order);

        return $description === '' ? null : 'Refund for ' . $description;
    }

    /**
     * Work out which Magento order a reference coming back from PayMongo belongs to.
     *
     * Accepts exactly the two shapes this module sends: a reference_number
     * ("MGN-000000015") or a description ("Magento Order #MGN-000000015"). The label is
     * stripped only from the start, then exactly one prefix, so a store whose own
     * increment IDs begin with "MGN-" still resolves correctly ("MGN-MGN-777" → "MGN-777").
     *
     * Anything else returns null: an unprefixed "000000015", the "Order #MGN-…" sent
     * before PYM-22, or another system's "Shopify Order #000000015". Those would
     * otherwise mark a Magento order paid, or cancel it, on the strength of a payment
     * that belongs to someone else. A payment left unmatched this way can still be
     * confirmed with the admin Refresh from PayMongo button, which looks the order up by
     * its stored session or payment intent ID rather than by this reference.
     *
     * @param string $reference reference_number or description from a webhook payload
     * @return string|null Order increment ID, or null when the reference is not one this module sent
     */
    public function resolveIncrementId(string $reference): ?string
    {
        $value = trim($reference);
        if (str_starts_with($value, self::DESCRIPTION_LABEL)) {
            $value = substr($value, strlen(self::DESCRIPTION_LABEL));
        }

        if (!str_starts_with($value, self::PREFIX)) {
            return null;
        }

        $incrementId = substr($value, strlen(self::PREFIX));

        return $incrementId === '' ? null : $incrementId;
    }
}
