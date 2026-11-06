# Developer Integration Guide

**PayMongo for Magento 2 — v1.1.3**

This guide is for engineers extending, customising, or integrating with the PayMongo module.

---

## Module architecture

```
PayMongo/Payment/
├── Block/
│   ├── Adminhtml/
│   │   ├── Render/
│   │   │   ├── Header.php                      # Branding header on the "General" config tab
│   │   │   ├── AccountCapabilities.php         # "Check PayMongo Account Status" button + results
│   │   │   ├── PaymentMethodsTable.php         # Section frontend_model — wraps method rows in one <table>
│   │   │   └── PaymentMethodsRedirectNotice.php# Entry on the stock Sales > Payment Methods grid
│   │   └── System/Config/Form/
│   │       ├── PaymentMethodRow.php            # Group frontend_model — one <tr> per payment method
│   │       └── Field/BareInput.php             # Field frontend_model — bare input for a table cell
│   └── Checkout/Payment/
│       ├── MethodIcon.php                      # Per-method icon block — not used by Luma checkout (see Model/ConfigProvider)
│       └── AllInOneMethodIcon.php               # Multi-logo + overflow badge icon for the all-in-one method
├── Controller/
│   ├── Adminhtml/
│   │   ├── Config/CheckCapabilities.php    # AJAX: account capabilities check
│   │   ├── Order/CapturePayment.php        # "Capture from PayMongo": retries a failed capture
│   │   ├── Order/RefreshStatus.php         # Manual payment status refresh
│   │   └── Reconciliation/                 # Reconciliation admin screen
│   └── Payment/
│       ├── Redirect.php                    # Sends customer to PayMongo checkout
│       ├── Success.php                     # Handles return after payment
│       ├── Failed.php                      # Handles cancelled/failed payment
│       └── Webhook.php                     # Receives async PayMongo events
├── Gateway/
│   └── PaymongoClient.php                  # All PayMongo API calls
├── Helper/
│   └── Config.php                          # Reads encrypted config values
├── Logger/
│   ├── Handler.php                         # Writes to var/log/payment.paymongo.log
│   ├── Logger.php                          # General logger
│   └── AuditLogger.php                     # AUDIT: prefixed admin action log
├── Model/
│   ├── AdminNotice.php                     # Admin-only error messages from observers
│   ├── GraphQl/SchemaGenerator.php         # webonyx/graphql-php ≥15 compat fix
│   ├── Order/AuthorizationExpiry.php       # When an Authorize Only order's card hold expires
│   ├── Order/PaymentCapture.php            # Captures a card hold and invoices; shared by shipment and button
│   ├── Checkout/EnabledMethodTypes.php     # Which individual methods are enabled + their checkout icons
│   ├── ConfigProvider.php                  # Exposes icon URLs to Luma checkout via checkoutConfig
│   ├── Method/
│   │   ├── AbstractPaymongoMethod.php      # Base class for all methods
│   │   ├── Card.php
│   │   ├── GCash.php
│   │   ├── GrabPay.php
│   │   ├── GooglePay.php
│   │   ├── Maya.php
│   │   ├── ShopeePay.php
│   │   ├── BillEase.php
│   │   ├── BpiOnline.php
│   │   ├── UnionBankOnline.php
│   │   ├── QrPh.php
│   │   └── Dob.php                         # Direct Online Banking — retired, kept for past orders
│   ├── Resolver/                           # GraphQL resolvers
│   ├── ViewModel/CurrencyCheck.php         # Checkout currency notice
│   └── Config/Source/PaymentAction.php     # Authorize / Capture source model
├── Observer/
│   ├── Adminhtml/WarnAuthorizationExpiry.php # Hold expiry message on the order and New Shipment pages
│   ├── CaptureOnShipment.php               # Captures card auth when shipment created
│   └── ReleaseHoldOnCancel.php             # Releases the card hold when the order is cancelled
├── Plugin/
│   ├── Block/Adminhtml/
│   │   ├── Order/AddCaptureButton.php      # Adds "Capture from PayMongo" button
│   │   └── Order/AddRefreshButton.php      # Adds "Refresh from PayMongo" button
│   └── Sales/
│       └── RejectPartialInvoicePlugin.php  # Refuses part-invoicing an order on a card hold
└── etc/
    ├── csp_whitelist.xml                   # Content Security Policy
    ├── schema.graphqls                     # GraphQL schema
    ├── adminhtml/
    │   ├── di.xml
    │   ├── menu.xml
    │   ├── routes.xml
    │   └── system.xml
    └── frontend/
        └── di.xml
```

### Payment Methods admin table

`Stores → Configuration → Sales → Payment Methods → PayMongo → Payment Methods` renders every individual method as one row in a flat table instead of Magento's default one-collapsible-fieldset-per-method layout. This is built from three custom `frontend_model` classes rather than a custom admin UI Component, so it keeps Magento's real config fields, scope handling, and `<depends>` behavior intact:

| Class | `frontend_model` scope | What it does |
|---|---|---|
| `Block\Adminhtml\Render\PaymentMethodsTable` | Section (`paymongo_payment_methods`) | Overrides `_toHtml()`, renders the form as usual, then regex-wraps the contiguous run of `<tr class="paymongo-method-row">` markup in one `<table>` |
| `Block\Adminhtml\System\Config\Form\PaymentMethodRow` | Group (one per method) | Matches its `active`/`title`/`sort_order`/`payment_action` fields by id suffix and assembles them into one `<tr>` |
| `Block\Adminhtml\System\Config\Form\Field\BareInput` | Field (`active`, `title`, `sort_order`, `payment_action`) | Renders a field as a bare input (no label/scope column), preserving the `row_<htmlId>` wrapper id and inherit-checkbox behavior Magento's own dependency JS (`mage/adminhtml/form.js`) and inherit-checkbox click handler (`mage/adminhtml/tools.js`'s `toggleValueElements`) rely on |

If you add a new payment method group to `system.xml`, give its group the `PaymentMethodRow` frontend_model and its `active`/`title`/`sort_order` fields the `BareInput` frontend_model to have it appear correctly in the table — a group without these will render nothing (its content is entirely determined by its frontend_model).

---

## Payment method class hierarchy

All payment methods extend `AbstractPaymongoMethod`, which extends Magento's `AbstractMethod`.

```
Magento\Payment\Model\Method\AbstractMethod
└── PayMongo\Payment\Model\Method\AbstractPaymongoMethod
    ├── Card             (paymongo_card)
    ├── GCash            (paymongo_gcash)
    ├── GrabPay          (paymongo_grab_pay)
    ├── GooglePay        (paymongo_google_pay)
    ├── Maya             (paymongo_maya)
    ├── ShopeePay        (paymongo_shopeepay)
    ├── BillEase         (paymongo_billease)
    ├── QrPh             (paymongo_qrph)
    ├── BpiOnline        (paymongo_bpi_online)
    ├── UnionBankOnline  (paymongo_unionbank_online)
    └── Dob              (paymongo_dob)       # retired, never active; kept for past orders
```

Key methods on `AbstractPaymongoMethod`:

| Method | Description |
|---|---|
| `getPaymongoMethodType(): string` | Returns the PayMongo API `payment_method_type` (e.g. `gcash`) |
| `getCheckoutSessionTypes(): string[]` | Returns the types this method's checkout session offers — its own type, except the retired `Dob`, which adds `dob_ubp` while UnionBank Online is enabled, for orders placed with it |
| `isAvailable(?CartInterface $quote): bool` | Returns false for non-PHP carts or when method is disabled |
| `refund(InfoInterface $payment, $amount): self` | Issues a PayMongo refund; throws `LocalizedException` on failure |

---

## Adding a new payment method

1. Create `Model/Method/YourMethod.php`:

```php
namespace PayMongo\Payment\Model\Method;

class YourMethod extends AbstractPaymongoMethod
{
    public const CODE = 'paymongo_yourmethod';

    protected $_code = self::CODE;

    public function getPaymongoMethodType(): string
    {
        return 'yourmethod'; // PayMongo API payment_method_type value
    }
}
```

2. Add configuration in `etc/config.xml`:

```xml
<paymongo_yourmethod>
    <model>PayMongo\Payment\Model\Method\YourMethod</model>
    <active>0</active>
    <title>PayMongo — Your Method</title>
    <order_status>pending</order_status>
    <payment_action>authorize_capture</payment_action>
    <allowspecific>0</allowspecific>
</paymongo_yourmethod>
```

3. Add the admin configuration fields in `etc/adminhtml/system.xml` following the pattern of any existing method.

4. Add `isBillingAddressRequired` to `checkout_index_index.xml`.

5. Give the method a checkout icon in `Model/Checkout/EnabledMethodTypes::TYPE_ICONS` (keyed by PayMongo API type) and `Model/ConfigProvider::ICONS` (keyed by method code), and a group in `Model/Checkout/MethodGroupLabel::CODE_GROUPS` so it is named in the collapsed group header's label. A method left out of `CODE_GROUPS` is not dropped from the label — it counts towards the trailing "and More" — but it will not get a group of its own until it is listed.

---

## PaymongoClient API

All direct PayMongo API calls go through `Gateway/PaymongoClient.php`. Inject it via DI:

```php
public function __construct(
    private readonly \PayMongo\Payment\Gateway\PaymongoClient $paymongoClient
) {}
```

Available methods:

| Method | Description |
|---|---|
| `createCheckoutSession(Order $order, string\|array\|null $paymentMethodType, bool $authorizeOnly): array` | Creates a hosted checkout session for one or more PayMongo types |
| `retrieveCheckoutSession(string $sessionId, ?int $storeId): array` | Gets session status |
| `retrievePaymentIntent(string $paymentIntentId, ?int $storeId): array` | Gets payment intent status |
| `createRefund(string $paymentId, int $amountCentavos, string $reason, ?string $notes, ?int $storeId): array` | Issues a refund |
| `capturePaymentIntent(string $paymentIntentId, int $amountCentavos, ?int $storeId): array` | Captures an authorised card payment |
| `cancelPaymentIntent(string $paymentIntentId, ?int $storeId): array` | Cancels a payment intent, releasing a card hold |
| `getPayments(int $createdGte, int $createdLte, ?int $storeId): array` | Fetches payments for reconciliation |
| `getRefunds(int $createdGte, int $createdLte, ?int $storeId): array` | Fetches refunds for reconciliation |

All methods throw `\Magento\Framework\Exception\LocalizedException` on API errors.

### PayMongo attribution metadata (`metadata.plugin`)

The resources the client creates — checkout sessions and refunds — are sent with
`metadata.plugin = "magento_v2"` so PayMongo can attribute the transaction to this
Magento 2 plugin. Both the key and the value are agreed with PayMongo. The value comes
from `PaymongoClient::METADATA_PLUGIN` and is applied centrally in `buildMetadata()`,
so any new create-call should include `'metadata' => $this->buildMetadata()` in its
attributes. The `/capture`, `/cancel` and `/expire` calls carry no metadata of their
own — they act on a resource that already has it.

---

## Webhook events

The webhook controller (`Controller/Payment/Webhook.php`) handles:

| PayMongo event | Action |
|---|---|
| `checkout_session.payment.paid` | Calls `processSuccessfulPayment()` — sets order to Processing, creates invoice |
| `payment.paid` | Same as above (legacy event) |
| `payment.failed` | Adds a comment with PayMongo's reason; the order stays Pending Payment so the customer can retry on PayMongo's page (PYM-32). Before 2.0.1 it cancelled the order |

Every event is checked against the order's stored payment intent or checkout session first (`isForOrder()`), so another installation's order with the same number is never touched. A payment for an order that is already cancelled raises `Model\Order\PaidCancelledOrder`: an "ACTION NEEDED" comment and a critical admin notification (`NotifierInterface::addCritical()`), once per payment intent (`additional_information['paymongo_paid_after_cancel']`). Success raises it too, and `ReleaseHoldOnCancel` and `CloseCheckoutOnCancel` when the order turns out to be charged at cancellation.

Cancelling any PayMongo order runs `Observer/CloseCheckoutOnCancel.php` (`order_cancel_after`). While the customer could still pay (intent awaiting a payment method or a next action, or no intent yet), it expires the checkout session (`POST /v1/checkout_sessions/{id}/expire`), so PayMongo's page stops accepting payments. Abandoned checkouts are cancelled by the Failed controller (the customer leaves PayMongo's page) or Magento's `sales_clean_orders` cron, which only selects orders whose *status* is `pending_payment`. Every method has `payment_action` set in `etc/config.xml` so that Magento runs its `initialize()` and the order starts in Pending Payment; before 2.0.1 only Card and All-in-One did, and the others started in New, which the cron skips.

The Failed controller only cancels the order the visitor's checkout session placed (`getLastOrderId()`), and only a PayMongo order still awaiting payment, so the cancel URL cannot be used to cancel other customers' orders. It works under the order lock, and asks PayMongo first (`CheckoutStart::sessionState()`): a session that is paid, whose payment is still processing, or that waits on the customer's 3DS check or e-wallet app (`awaiting_next_action`), is left for the webhook to confirm, and the cart is not restored. When PayMongo cannot be asked the order is left for the cron.

`Model\Order\CheckoutStart` is the one place that reads a checkout session's state (`sessionState()`: none, open, action, paid, processing or closed) and expires it (`closeSession()`), for the Failed controller, `CloseCheckoutOnCancel`, `ReleaseHoldOnCancel` and opening a new page. While an operation runs it asks PayMongo once per session, so a cancellation checks PayMongo once, however many of those steps read it; the answer is forgotten (`forget()`) when the cancellation is done, after a hold is released, and when the session is closed, so a long-running cron process never acts on an old state. `ReleaseHoldOnCancel` still asks about the order's stored intent directly when it differs from the session's (a payment completed on an earlier page). Redirect and the `createPaymongoCheckoutSession` resolver open a page through `CheckoutStart::openPage($order)`, the one place a page is created. It takes the payment types (`paymentTypes()`) and Authorize Only from the order's own payment method, never from the caller (the resolver refuses a `method_code` that doesn't match the order), and runs `CheckoutStart::open()`: which takes the order lock, reloads the order, refuses an order no longer awaiting payment or whose previous session was paid or is still processing, expires a previous session that could still be paid, and records the new session with `recordSession()`, which clears the previous intent and payment IDs and keeps the previous session and intent in `additional_information['paymongo_previous_checkouts']`. The webhook's `isForOrder()` accepts those too, and makes the paid one the order's current checkout (`adoptCheckout()`), so a payment completed on an earlier page still confirms the order. So only one PayMongo page can take a payment for an order, even on a double click. Without it a refresh left the earlier page payable, and a payment there would be ignored by the webhook's `isForOrder()` check.

The `payment.failed` comment is saved through `OrderStatusHistoryRepositoryInterface`, not by saving the order, so it does not move the order's `updated_at`, from which the stale-order cron counts the lifetime. `PaidCancelledOrder` is keyed by the checkout session, and asks PayMongo whether the card is held or charged when its caller cannot tell; when PayMongo reports neither (a hold already released), no alert is raised.

When the order lock cannot be taken, the webhook throws `Model\Order\OrderBusyException`: it answers PayMongo with 503 and does not mark the event processed, so PayMongo delivers it again. Before, a busy order was logged and answered 200, and the event was lost.

Every webhook is verified using HMAC-SHA256 against the signing secret stored in config. Replays are blocked via a nonce check.

To observe webhook processing, listen to Magento's standard order events (`sales_order_save_after`, etc.) — no custom PayMongo events are dispatched.

## Order reference sent to PayMongo

`Model/Order/PaymentReference.php` owns the string PayMongo carries for an order in both
directions. Nothing else should build or parse it.

```php
public function __construct(
    private readonly \PayMongo\Payment\Model\Order\PaymentReference $paymentReference
) {}

$reference   = $this->paymentReference->build($order);                      // 'MGN-000000015'
$description = $this->paymentReference->buildDescription($order);           // 'Magento Order #MGN-000000015'
$incrementId = $this->paymentReference->resolveIncrementId($fromWebhook);   // '000000015'
```

| Method | Description |
|---|---|
| `build(?OrderInterface $order): string` | The order's increment ID with `PaymentReference::PREFIX` in front — the `reference_number` on a checkout session. Returns `''` for a null order or an empty increment ID |
| `buildDescription(?OrderInterface $order): string` | `PaymentReference::DESCRIPTION_LABEL` + `build()` — `'Magento Order #MGN-000000015'`. The `description` on checkout sessions and DOB payment intents |
| `buildRefundNotes(?OrderInterface $order): ?string` | `'Refund for '` + `buildDescription()` — the `notes` on a refund. Tolerates a null order and returns `null`, so refund callers need no guard and no dangling `'Refund for '` is sent |
| `resolveIncrementId(string $reference): ?string` | The Magento increment ID for a `reference_number` or `description` coming back from PayMongo, or `null` when it is not one this module sent |

**Never send `$order->getIncrementId()` to PayMongo directly.** Every outbound field that
names an order goes through `build()`, `buildDescription()` or `buildRefundNotes()` — `reference_number` and
`description` on a checkout session, `description` on a DOB payment intent, and the `notes`
on a refund — so all of them are tagged, not some. The description is also how BPI and
UnionBank webhooks are matched back to their order (a payment intent has no
`reference_number`), so changing its shape means changing `resolveIncrementId()` with it.

**Never strip the prefix by hand either.** `resolveIncrementId()` accepts exactly the two
shapes this module sends, strips the label only from the start and then exactly one
prefix, so a store whose own increment IDs begin with `MGN-` still round-trips
(`MGN-MGN-777` → `MGN-777`). Everything else returns `null` and the webhook ignores it:
PayMongo delivers every payment on the account, including ones other systems created, and
a loose match would let one of those mark a Magento order paid or cancel it. A
hand-rolled `str_replace()` gets both of these wrong.

**`PREFIX` is a constant, not config.** The tag is what the merchant's dashboard filters
and exports are built around, so there is deliberately no admin field for it — a store
view clearing it would break the identification it exists to provide. Changing the
constant strands every payment still in flight, because `resolveIncrementId()` only
accepts the current prefix. Those orders then have to be confirmed with the admin
**Refresh from PayMongo** button, which looks them up by stored session or payment intent
ID instead.

---

---

## Authorize-and-capture flow

Authorize Only is a setting on the standalone Card method (`payment/paymongo_card/payment_action`), and only that method honours it. All-in-One Checkout (`paymongo`) always captures immediately: PayMongo cannot mix a manual-capture hold with other methods in one hosted session, and accounts without Hold Then Capture reject the hold with `organization_not_allowed` (PYM-24).

`Model\Order\CaptureMode` owns the decision. Nothing else should read `getCardPaymentAction()` to decide how an order is captured.

```php
public function __construct(
    private readonly \PayMongo\Payment\Model\Order\CaptureMode $captureMode
) {}

// When creating the checkout session (Controller\Payment\Redirect, Model\Resolver\CreateCheckoutSession)
$authorizeOnly = $this->captureMode->shouldAuthorizeOnly($methodCode, $storeId); // Card + Authorize Only
$this->captureMode->record($payment, $authorizeOnly);                         // caller saves the order

// Everywhere after payment (Success, Webhook, RefreshStatus, CaptureOnShipment, Reconciliation\Service)
$isAuthorizeOnly = $this->captureMode->isAuthorizeOnly($order);
```

| Method | Description |
|---|---|
| `shouldAuthorizeOnly(string $methodCode, int $storeId): bool` | True only for `Card::CODE` with Payment Action `authorize`. Never for the all-in-one method |
| `record(OrderPaymentInterface $payment, bool $authorizeOnly): void` | Stores the decision in `additional_information['paymongo_authorize_only']` |
| `isAuthorizeOnly(Order $order): bool` | The stored decision. Orders created before it was stored fall back to the pre-2.0.1 rule (Card **or** all-in-one, plus Payment Action `authorize`) |

**Why the decision is stored, not re-derived.** Success, the webhook, the Refresh button, reconciliation and capture-on-shipment all run after the session was created, sometimes days later. Re-reading config there means changing Payment Action or the display mode while an order is in flight flips how it is treated: a held order would be invoiced without being captured, or a charged order held for a capture that never comes. Reading the stored value means every step agrees with what PayMongo was actually asked for.

**Why the fallback still includes the all-in-one method.** Before 2.0.1, All-in-One under Authorize Only narrowed its session to card-only and requested a hold. On accounts with Hold Then Capture enabled that worked, so such orders may be on hold at upgrade. Without the fallback, shipping them would skip the capture and the authorisation would expire uncollected.

`Success` and `RefreshStatus` also treat a payment intent status of `awaiting_capture` as authorize-only, whatever `isAuthorizeOnly()` says, because that is PayMongo reporting that the funds are on hold.

Flow:

1. `Redirect.php` (or the GraphQL resolver) calls `createCheckoutSession()` with `$authorizeOnly = true` for a Card order, which sets `capture_type = manual` on the PayMongo session, and records the decision on the payment
2. On the `Success` callback (or the async webhook, whichever processes first — both are idempotent against each other via an `Order::STATE_PROCESSING` guard), the order is moved to Processing **without** creating an invoice
3. When the merchant creates a shipment, `Observer/CaptureOnShipment.php` fires once the shipment is committed (on `sales_order_shipment_save_commit_after`), under the order lock (`paymongo_order_{increment_id}`) the webhook, Success and Refresh use. Before 2.0.1 it ran on `sales_order_shipment_save_after`, inside the shipment's save transaction: a failure while invoicing rolled the shipment back after PayMongo had charged the customer, and the PayMongo calls held the order's row locks while they waited. An order that is already invoiced is skipped (see step 9), and so is one whose lock is held: the admin is told to use the button instead
4. The observer calls `PaymongoClient::capturePaymentIntent()` with the stored `paymongo_payment_intent_id`
5. On successful capture, an invoice is created with **Capture Online** (the methods' `capture()` and `refund()` are one shared class, `Model\Method\CaptureAndRefund`, used by every PayMongo method, the legacy all-in-one one included), like the webhook's for a payment made at checkout. The PayMongo payment (`pay_…`) is read from the capture response, or from the intent when PayMongo had already captured it, and stored as `paymongo_payment_id`; if a capture response names none, `PaymentCapture::findPaymentId()` asks once for the intent's paid payment, and `ensurePaymentId()` does the same when Capture from PayMongo or Capture Online invoices a "captured but not invoiced" order. Refunds use the same lookup when no ID is stored, and record PayMongo's refund ID (`ref_…`) as the refund transaction, so each partial refund of an invoice keeps its own. `AbstractPaymongoMethod::capture()` makes it the invoice's transaction ID; the result is recorded as `captured` before the invoice is registered, so `captureOnInvoice()` does not call PayMongo again. Magento offers the online **Refund** only for an invoice with a transaction ID. Before 2.0.1 the invoice used Capture Offline and had none, so these orders could only be refunded offline (PYM-31)
6. If the capture call fails, the observer asks PayMongo for the payment intent's status (`PaymentConfirmation::checkPaymentIntent()`). `succeeded` means PayMongo had already captured it (see below), so the order is still invoiced. `awaiting_capture`, `awaiting_payment_method`, `awaiting_next_action` or `cancelled` means the customer was not charged. Any other status, such as `processing`, or no answer leaves it unconfirmed, and the comment says to check the Dashboard before collecting payment another way, so the customer is not charged twice. Either way no invoice is created, the order is put on hold with a comment naming the intent's status, and the admin gets an error message. The shipment is saved either way
7. The outcome is stored in `additional_information['paymongo_capture_result']`, with the intent's status at the time in `paymongo_capture_status`. Once the outcome is set, later shipments of the order — partial shipments, or a shipment re-saved to add tracking — do not capture again
8. If invoicing fails after PayMongo took the money, the order object in memory is not saved (the failed `register()` has already changed its totals). A freshly loaded copy records `captured_not_invoiced`, a comment saying the customer HAS been charged, and goes On Hold, so the admin can create the invoice with the button without charging again
9. An order whose outcome is `not_captured`, `unconfirmed` or `captured_not_invoiced`, or that has shipped with no outcome recorded (shipped before 2.0.1, or skipped because it was locked), and that has not been invoiced, gets a **Capture from PayMongo** button (`Plugin/Block/Adminhtml/Order/AddCaptureButton.php`, ACL `Magento_Sales::capture`). It POSTs to `Controller/Adminhtml/Order/CapturePayment.php`, which takes the order lock, re-reads the order, and runs the same capture: captured or already captured releases the hold and invoices; anything else only adds a comment and updates the outcome
10. An invoice created by hand with **Capture Online** captures the hold too: `AbstractPaymongoMethod::capture()` calls `PaymentCapture::captureOnInvoice()`, which stops the invoice with an error if PayMongo does not capture. Before PYM-28, Capture Online only recorded a transaction ID, so an Authorize Only order invoiced before shipping was marked paid without the card being charged. Because invoicing now means the money was taken (Capture Online) or collected another way (Capture Offline), shipping an invoiced order never captures. While the hold can still be captured, an invoice for only part of the order is refused, since PayMongo captures a hold once and this module always captures all of it. `Plugin/Sales/RejectPartialInvoicePlugin.php` calls `PaymentCapture::assertFullInvoice()` before `Invoice::register()` (admin, custom code) and `PayOperation::execute()` (REST API), so a refused invoice never changes the order; `captureOnInvoice()` checks the amount again before PayMongo is asked, which covers capturing a "Not Capture" invoice. Once PayMongo has taken the money or dropped the hold, any invoice is allowed, so a charged order can always be invoiced. The admin invoice page only allows full invoices already (the methods set `_canCapturePartial = false`), but the REST API and custom code do not check that

The shipment, the button and Capture Online all go through `Model\Order\PaymentCapture`, so they decide the same way whether the customer was charged. Which orders PayMongo should still be holding (`isAwaitingCapture()`, used by the expiry banner) and which the button can act on (`canRetry()`) are defined there too.

Cancelling the order (`order_cancel_after`, so the admin, the customer leaving PayMongo's page and Magento's stale-order cron alike) runs `Observer/ReleaseHoldOnCancel.php`. It asks PayMongo for the intent's status, through the checkout session if no intent ID is stored yet, and calls `cancelPaymentIntent()` only for one `awaiting_capture`. An intent that has already `succeeded` means PayMongo took the money although Magento has no invoice, so the order is flagged for a refund through PayMongo instead. A failure never blocks the cancellation; it is recorded as an order comment and shown to the admin. When PayMongo cannot be asked at all, the comment says only that the hold could not be checked: most orders cancelled this way, such as abandoned Pending Payment ones, never had a hold.

PayMongo releases an uncaptured hold after 7 days and returns no expiry date. `Model\Order\AuthorizationExpiry` works it out as the order's `created_at` plus 7 days, for orders `PaymentCapture::isAwaitingCapture()` says PayMongo still holds: none after a capture found the hold gone, captured it, or could not confirm. The card is authorised on PayMongo's page after the order is created, so the date is a few minutes early at most, never late. `Observer/Adminhtml/WarnAuthorizationExpiry.php` shows it on the order view and New Shipment pages: a notice, a warning within 2 days of expiry (the Dashboard's **Expiring Soon**), or an error once expired.

Error messages from these observers go through `Model\AdminNotice`, which only adds them in the admin area. The observers also run from the webhook, REST and cron, where a session message reaches nobody.

The payment intent ID is stored in `order_payment.additional_information['paymongo_payment_intent_id']`.

Note: PayMongo's sandbox/test mode reportedly did not always enforce `capture_type: manual`, so a payment could be charged at checkout instead of held. When that happens, the capture call fails with `resource_invalid_state`, the intent reads `succeeded`, and the order is invoiced as usual. As of October 2026 the sandbox does hold card payments (`awaiting_capture`), so the capture call itself succeeds. Do not treat `resource_invalid_state` itself as "already captured": an authorisation that expired or was cancelled fails with it too. Before 2.0.1 the observer did, and invoiced those orders with no money taken (PYM-28).

---

## Order payment additional information

The following keys are stored in `order_payment.additional_information`:

| Key | Set when | Contains |
|---|---|---|
| `paymongo_checkout_session_id` | Redirect controller | PayMongo checkout session ID (`cs_…`) |
| `paymongo_payment_intent_id` | Success callback / DOB flow | PayMongo payment intent ID (`pi_…`) |
| `paymongo_payment_id` | Success callback | PayMongo payment ID (`pay_…`) — needed for refunds |
| `paymongo_capture_result` | `PaymentCapture` (shipment, Capture from PayMongo or Capture Online) | `captured`, `not_captured`, `unconfirmed` or `captured_not_invoiced` — stops later shipments capturing again, and decides whether the Capture from PayMongo button shows |
| `paymongo_capture_status` | `PaymentCapture` | The payment intent's status at that capture attempt — tells the expiry banner whether PayMongo still holds the card |

---

## Logging

Inject the logger:

```php
public function __construct(
    private readonly \PayMongo\Payment\Logger\Logger $logger
) {}
```

For audit logging of privileged admin actions:

```php
public function __construct(
    private readonly \PayMongo\Payment\Logger\AuditLogger $auditLogger
) {}

// Usage
$this->auditLogger->logRefund($order->getIncrementId(), $amount, $paymentId, $notes);
$this->auditLogger->logCapture($order->getIncrementId(), $amount, $paymentIntentId);
```

All logs go to `var/log/payment.paymongo.log`. Audit entries are prefixed with `AUDIT:`.

---

## Config helper

```php
public function __construct(
    private readonly \PayMongo\Payment\Helper\Config $config
) {}

$secretKey    = $this->config->getSecretKey($storeId);
$publicKey    = $this->config->getPublicKey($storeId);
$webhookSecret = $this->config->getWebhookSecret($storeId);
$paymentAction = $this->config->getCardPaymentAction($storeId); // 'authorize' or 'authorize_capture'
```

---

## ACL resources

| Resource ID | Grants access to |
|---|---|
| `PayMongo_Payment::config` | PayMongo payment configuration |
| `PayMongo_Payment::view` | View-only access to PayMongo settings |
| `PayMongo_Payment::reconciliation` | Reconciliation admin screen |

---

## Running tests

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/PayMongo/Payment/Test/
```

---

## Support

Open an issue in the repository or contact **support@devteam.com**.
