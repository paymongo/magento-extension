# PayMongo — Magento 2 Payment Module

A Magento 2 payment integration for [PayMongo](https://www.paymongo.com/), the leading Philippine payment gateway. Supports all PayMongo payment methods through a hosted checkout redirect flow. Compatible with Luma (default Magento theme) checkout, Hyvä themes that use the Luma checkout fallback, and headless storefronts.

**Maintained by [DevTeam](https://devteam.com) — [Support Portal](https://support-devteam-paymongo.zendesk.com/hc/en-us) · support@devteam.com**

---

## Features

- Hosted checkout redirect (no PCI scope on your server — SAQ A compliant)
- Master Enable/Disable switch for the whole module, independent of individual method toggles
- All PayMongo payment methods with individual on/off toggles per store, shown as a single flat table in admin:
  **Credit/Debit Card**, **GCash**, **GrabPay**, **Google Pay**, **Maya**, **ShopeePay**, **BillEase**, **BPI Online**, **UnionBank Online**, **QR Ph**
- Every setting — API keys, webhook secret, environment, method toggles, checkout display — can be overridden per store view or per website, not just globally
- Payment Display Mode toggle: show each method separately at checkout, or a single all-in-one "PayMongo" option that lists every enabled method together on PayMongo's hosted page
- Works on Luma (default Magento theme) checkout, including Hyvä themes that use the Luma checkout fallback — no theme migration required
- Headless storefront support via GraphQL API
- Automatic invoice creation on payment confirmation
- Online refunds (full and partial) via Magento credit memo flow
- Authorize-now, capture-on-shipment for card payments
- Webhook signature verification with replay protection
- Encrypted API key storage
- Reconciliation tool — cross-check Magento orders against PayMongo transactions
- Audit logging — refund and capture actions logged with admin identity and timestamp
- All API calls logged with PayMongo request ID for faster support resolution
- Non-PHP currency carts rejected with a clear error (PHP only)
- Dedicated log file: `var/log/payment.paymongo.log`

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | ≥ 8.1 |
| Magento Open Source / Adobe Commerce | ≥ 2.4.6 |

---

## Installation

### Via Composer (recommended)

```bash
composer require paymongo/module-magento2-paymongo
bin/magento module:enable PayMongo_Payment
bin/magento setup:upgrade
bin/magento cache:flush
```

### Manual installation

1. Copy the module directory into `app/code/PayMongo/Payment/`.
2. Run the setup commands above.

---

## Configuration

1. In the Magento admin, go to **Stores → Configuration → PayMongo → General**.
2. In **General Settings**, set **Enable PayMongo** to **Yes** — this is the master switch for the whole module; nothing PayMongo-related appears at checkout while it's **No**, regardless of any other setting.
3. Expand the **Shared Credentials** group.
4. Configure global settings:

| Field | Description |
|---|---|
| Environment | Which credential set below is actually used — **Sandbox (Test Mode)** or **Production (Live Mode)** |
| Sandbox Secret Key | Your PayMongo test secret key (`sk_test_…`) |
| Sandbox Public Key | Your PayMongo test public key (`pk_test_…`) |
| Sandbox Webhook Secret | Signing secret from your **test-mode** webhook endpoint |
| Production Secret Key | Your PayMongo live secret key (`sk_live_…`) |
| Production Public Key | Your PayMongo live public key (`pk_live_…`) |
| Production Webhook Secret | Signing secret from your **live-mode** webhook endpoint |

Only the set for the environment you are currently using needs to be filled in. Both sets are retained when you switch, and each environment's webhook secret is required for webhook verification in that environment.

5. Go to **Stores → Configuration → PayMongo → Payment Methods**. Each payment method has its own group with:

| Field | Description |
|---|---|
| Enabled | Enable or disable this method per store |
| Title | Customer-facing label shown at checkout |
| New Order Status | Order status before payment is confirmed |
| Payment from Applicable Countries | Restrict to specific countries if needed |

6. Card-specific option:

| Field | Description |
|---|---|
| Payment Action | `Authorize and Capture` (default) or `Authorize Only` — charge on shipment |

API keys are stored **encrypted** in the database using Magento's built-in encryptor.

> **Upgrading from v1.0?** You must regenerate the PayMongo webhook secret in your PayMongo dashboard and paste the new value into the **Webhook Secret** field. v1.1.0.0 enforces signature verification — the existing webhook will be rejected without this step.

### Payment Display Options

In **PayMongo → Payment Methods → Checkout Display**, the **Payment Display Mode** field controls how checkout presents PayMongo methods:

| Mode | Behavior |
|---|---|
| **Separate Payment Methods** (default) | Each enabled method (Card, GCash, GrabPay, etc.) appears as its own line item at checkout, grouped under one collapsible PayMongo heading, each redirecting to a PayMongo checkout session for just that method. |
| **All-in-One Checkout** | Customers see a single "PayMongo" option. It opens one hosted PayMongo checkout session listing every method you currently have individually enabled below — you don't need to re-enable anything, the two settings work together. |

In **Separate Payment Methods** mode, the collapsible heading names what is inside it, so shoppers can see what is on offer before they open it:

> **PayMongo (QRPh, Cards, E-Wallets, Banking, and More)**

That list is built from the methods you actually have enabled, not written into the module, so it stays accurate on its own. Switch QR Ph off and the heading reads **PayMongo (Cards, E-Wallets, Banking, and More)**; enable nothing but GCash and Maya and it reads **PayMongo (E-Wallets)**. There is no setting to keep in step with your payment settings, and nothing to edit when you change them.

Methods are grouped as follows: **QRPh** (QR Ph), **Cards** (Credit / Debit Card), **E-Wallets** (GCash, GrabPay, Maya, ShopeePay, Google Pay), **Banking** (BPI, UnionBank) and **More** (BillEase). **and More** appears only when something outside the four named groups is enabled.

The **All-in-One Checkout** option is unaffected — it is a single row rather than a collapsible group, and it already shows payment logos with a "+N" counter alongside its title.

Methods are listed in **Sort Order**, which by default puts **QR Ph**, **Credit / Debit Card** and **GCash** first, followed by GrabPay, Maya, Google Pay, ShopeePay, BillEase, BPI and UnionBank. All-in-One Checkout follows the same order for the logos beside its title and for the methods it sends to PayMongo's hosted page. Change a method's **Sort Order** to rearrange them.

This field lives at the top of the Payment Methods page, right above the method list it governs. Switching to **All-in-One Checkout** hides each method's **Title** and **Sort Order** fields (titles only apply in Separate mode; the saved Sort Order still decides the order of All-in-One's logos), but leaves **Enabled** visible and functional in both modes — it's still what decides which methods are included in the combined checkout page. Under the hood, switching modes also keeps the legacy `paymongo` method's own enabled flag in sync automatically.

### Reference Prefix

Everything sent to PayMongo that names an order carries the fixed prefix `MGN-`, so Magento transactions can be identified in a PayMongo account that also takes payments from other channels. Order `000000015` reaches the PayMongo dashboard as:

| Payment method | Field in PayMongo | Value |
|---|---|---|
| Card, GCash, Maya, GrabPay, Google Pay, ShopeePay, QR Ph, BillEase | Reference Number | `MGN-000000015` |
| all of the above | Description | `Magento Order #MGN-000000015` |
| BPI Online, UnionBank Online | Description | `Magento Order #MGN-000000015` |
| BPI Online, UnionBank Online | Reference Number | *(not available — see below)* |
| refunds, every method | Notes | `Refund for Magento Order #MGN-000000015` |

Magento's own order numbers are untouched — the order is still `000000015` in the admin, in emails, in the API and on the invoice. The prefix exists only in what PayMongo is told.

There is no admin setting for this. The prefix is what the merchant's dashboard filters, exports and reconciliation are built around, so it is a constant (`Model\Order\PaymentReference::PREFIX`) rather than something a store view can quietly clear.

**Older BPI Online and UnionBank Online orders carry no reference number.** Before this release those two went through PayMongo's Payment Intent flow, which has no `reference_number` field at all — only a description. Orders placed that way are still tagged, but in the description alone, so searching or filtering the PayMongo dashboard by reference number will not return them. Both now use Checkout Sessions like every other method, so new orders carry the reference number.

**The description names Magento as the source.** It is the line the PayMongo dashboard lists each payment by, so it reads `Magento Order #MGN-000000015` — where the payment came from and which order it pays for, without needing to know what `MGN-` stands for. `show_description` is enabled, so customers read the same line on the PayMongo payment page.

**Only references this module sent are matched.** The reference is not only a label: PayMongo echoes it back on the webhook that confirms the payment, and it is how the module works out which order to invoice. PayMongo sends every payment on the account to that webhook, including ones another system created, so only `MGN-000000015` and `Magento Order #MGN-000000015` are accepted. Anything else, such as another app's `Order #000000015`, is logged and ignored and can never mark a Magento order paid or cancel it.

---

---

## Checkout Flow

1. Customer selects a PayMongo method at checkout and places the order.
2. Order is created in **Pending Payment** state.
3. Customer is redirected to the PayMongo hosted checkout page for the selected method.
4. Customer completes payment.
5. PayMongo redirects back to either:
   - `paymongo/payment/success` — payment completed
   - `paymongo/payment/failed` — payment cancelled or failed
6. On success: order moves to **Processing**, an invoice is created automatically, and a confirmation email is sent.
7. On failure: the order is cancelled and the cart is restored.

**Currency:** Only PHP (Philippine Peso) is accepted. Carts in other currencies will not display PayMongo methods and will show a clear notice at checkout.

---

## Webhook Setup

Webhooks provide reliable async payment confirmation independently of the browser redirect.

### Webhook URL

```
https://your-store.com/paymongo/payment/webhook
```

### Registering the webhook in PayMongo

1. Log in to the [PayMongo Dashboard](https://dashboard.paymongo.com/).
2. Go to **Developers → Webhooks → Add Endpoint**.
3. Enter your webhook URL.
4. Enable the following events:
   - `payment.paid`
   - `checkout_session.payment.paid`
   - `payment.failed`
5. Save and copy the **Signing Secret**.
6. Paste the signing secret into **Stores → Configuration → PayMongo → General → Shared Credentials**, into the field matching the mode you created the endpoint in — **Sandbox Webhook Secret** or **Production Webhook Secret**. Repeat for the other mode when you set it up; each environment has its own endpoint and its own secret.

### Security

- Every incoming webhook is cryptographically verified against the PayMongo signature header. Requests without a valid signature are rejected.
- Replay protection: a signed webhook cannot be re-submitted to mark an order as paid twice.

> **Note:** The webhook endpoint intentionally bypasses Magento's CSRF protection, which is required for external API callbacks.

---

## Online Refunds

Refunds are initiated from the order's invoice: **Sales → Orders → [Order] → Invoices → [Invoice] → Credit Memo**.

- Click **Refund** (not "Refund Offline") to trigger an online refund via the PayMongo API.
- Start from the invoice, not the order. The order page's **Credit Memo** button creates an offline refund only, as Magento's prompt there says: it is not linked to an invoice, so there is no PayMongo payment to refund against.
- Both full and partial refunds are supported.
- Minimum refund amount: **₱1.00** (100 centavos).
- Supported refund reasons: `requested_by_customer` (default), `duplicate`, `fraudulent`, `others`.
- If a refund fails, PayMongo's reason is shown on the credit memo page — the credit memo is not saved, so the admin can fix the issue and retry immediately.
- PayMongo pays refunds from the account's pending payout and keeps its fee on every payment, so a new account's first refunds can fail with "Refund amount is greater than the available payout amount". Top up the PayMongo balance, then retry. See [Troubleshooting](docs/TROUBLESHOOTING.md).
- All refund actions are audit-logged with the admin username and timestamp.

---

## Authorize-Now, Capture-Later (Cards Only)

When **Payment Action** is set to `Authorize Only` on the card method:

- The customer's card is authorized at order placement but not charged.
- The charge is captured automatically when the merchant creates a shipment for the order.
- If PayMongo does not capture the charge — the authorisation expired or was cancelled, or the capture was declined — the shipment is still saved but no invoice is created. The order is put **On Hold** with a comment saying the customer has not been charged — or, if PayMongo could not confirm it either way, to check the PayMongo Dashboard before collecting payment again — and you see an error. Later shipments of the same order do not try to capture again.
- If PayMongo takes the money but the invoice cannot be created, the order is put **On Hold** with a comment saying the customer HAS been charged.
- Such orders get a **Capture from PayMongo** button (for admins allowed to capture payments), and so does an order that shipped without its payment being captured. It asks PayMongo to take the held money and, if that works, releases the hold and invoices the order. If PayMongo had already taken the payment — captured on PayMongo's side, say — it invoices the order without charging the customer again. If the card is no longer held, it tells you to collect payment another way.
- Creating an invoice yourself with **Capture Online** also takes the held money from PayMongo, and stops with an error if PayMongo does not capture it. Shipping an order you have already invoiced does not capture again.
- While the card is on hold, these orders can only be invoiced in full, all at once: PayMongo captures a card hold once. The admin invoice page already works this way; an invoice for part of the order through the REST API or custom code is refused.
- PayMongo releases an uncaptured hold after 7 days, and it can no longer be captured, so ship Authorize Only orders before then. The order page and the New Shipment page show when the hold expires: a notice while it is valid, a warning in its last 2 days (when the PayMongo Dashboard flags it **Expiring Soon**), and an error once it has expired. PayMongo returns no expiry date, so it is counted from when the order was placed, which can be a few minutes before the card was actually authorised.
- Cancelling the order releases the hold on PayMongo, so the customer's funds are freed straight away. This applies however the order is cancelled: from the admin, by the customer leaving PayMongo's page, or by Magento's cron for stale pending orders. If PayMongo cannot release it, the order is still cancelled, with a comment saying the funds may stay held until the hold expires. If PayMongo had already taken the money, the order comment and an error tell you to refund the customer through PayMongo.
- Other PayMongo methods (GCash, wallets, etc.) do not support this flow and always charge immediately.
- Your PayMongo account must have **Hold Then Capture** enabled. Without it PayMongo rejects the session with `organization_not_allowed` — see [TROUBLESHOOTING](docs/TROUBLESHOOTING.md).

**Authorize Only applies to the standalone Credit / Debit Card method only.** All-in-One Checkout always uses Authorize & Capture and shows every enabled method, whatever the Card method's Payment Action is set to — PayMongo cannot mix a card hold with other methods in one hosted session. The Card setting is kept while All-in-One is active, so switching back to Separate mode brings Authorize Only back with it.

Each order remembers how it was sent to PayMongo, so changing Payment Action or the display mode never changes how an order that is already in progress is captured.

---

## Reconciliation Tool

Admin → **Sales → PayMongo → Reconciliation**

Cross-checks Magento orders against PayMongo transactions for a given date range and flags discrepancies:

- A paid PayMongo transaction with no matching Magento invoice
- A refund in PayMongo with no Magento credit memo
- Mismatched amounts

Click **Export CSV** to download the discrepancies, payments, and refunds for the selected date range as a single CSV file — useful for sharing with accounting or archiving outside Magento.

Otherwise read-only in this release — discrepancies are surfaced for manual resolution.

### Multiple installations sharing one PayMongo account

PayMongo issues a single test key pair and a single live key pair per account — there's no built-in way to get separate credentials per environment. If you use the same test (or live) keys across more than one Magento installation (e.g. local and a shared dev server), be aware that Magento's order increment IDs restart at `000000001` in every fresh install, so two installations will eventually produce colliding increment IDs.

The Reconciliation Tool is unaffected by this: it matches every PayMongo transaction to a Magento order strictly by PayMongo's payment ID (globally unique, assigned by PayMongo), never by guessing from the order increment ID — so a shared key pair can never cause a payment from one installation to be wrongly matched against an unrelated order in another.

What shared keys do affect is scope: the Payments/Refunds tables and discrepancy checks are not filtered per installation, so for a given date range they'll show every installation's activity mixed together. The `MGN-` prefix marks a row as Magento's but is the same on every installation, so to tell two installations apart, cross-reference the order increment ID shown in each row against the install you're reconciling.

---

## Headless / GraphQL API

For headless storefronts (Next.js, PWA, mobile apps), the module exposes a GraphQL API at `/graphql`.

### Queries

```graphql
# List all enabled PayMongo methods for the current store (no auth required)
{
  paymongoPaymentMethods {
    code
    title
    paymongo_type
  }
}

# Poll order payment status after redirect (customer token required)
{
  paymongoOrderStatus(order_number: "000000055") {
    order_increment_id
    order_state
    session_status
    payment_id
    payment_intent_id
  }
}
```

### Mutation

```graphql
# Create a hosted checkout session after placeOrder (customer token required)
mutation {
  createPaymongoCheckoutSession(input: {
    cart_id: "masked_cart_id"
    method_code: "paymongo_gcash"
  }) {
    session_id
    checkout_url
    payment_method_type
  }
}
```

### Headless checkout flow

```
1. placeOrder (standard Magento mutation)
2. createPaymongoCheckoutSession → get checkout_url
3. Redirect customer to checkout_url
4. Customer pays on PayMongo hosted page
5. PayMongo redirects back to Magento success/cancel URL
6. Poll paymongoOrderStatus until order_state = "processing"
```

---

## Logging

All API interactions, webhook events, and admin actions are logged to:

```
var/log/payment.paymongo.log
```

Log level: `DEBUG` (includes request/response details). Sensitive data (card numbers, etc.) is never logged — only order IDs, amounts, and session/payment IDs.

Every API call logs the **PayMongo request ID** (`x-request-id`) — this is the first field PayMongo support asks for when a merchant files a ticket.

Audit entries for privileged actions (refunds, captures) are prefixed with `AUDIT:` and include the admin username:

```
grep "AUDIT:" var/log/payment.paymongo.log
```

---

## Documentation

| Document | Audience |
|---|---|
| [Install Guide](docs/INSTALL.md) | Merchants — step-by-step setup with screenshots guidance, no developer experience required |
| [Developer Guide](docs/DEVELOPER.md) | Engineers extending or customising the module |
| [GraphQL Reference](docs/GRAPHQL.md) | Headless integrators building on Next.js, PWA, or mobile apps |
| [Security Guide](docs/SECURITY.md) | PCI scope, API key rotation, webhook secret management, CSP |
| [Upgrade Guide](docs/UPGRADE.md) | Merchants and developers upgrading to a new release, or rolling back |
| [Troubleshooting](docs/TROUBLESHOOTING.md) | Indexed by PayMongo error code and common symptom |

---

## Development

### Module structure

```
PayMongo/Payment/
├── Controller/
│   ├── Adminhtml/Reconciliation/   # Reconciliation admin screen
│   └── Payment/                    # Redirect, Success, Failed, Webhook endpoints
├── Gateway/                        # PaymongoClient — API communication layer
├── Helper/                         # Config helper (reads encrypted config values)
├── Logger/                         # Custom Monolog logger → var/log/payment.paymongo.log
├── Model/
│   ├── GraphQl/                    # Schema generator (webonyx/graphql-php ≥15 compat fix)
│   ├── Method/                     # AbstractPaymongoMethod + one class per payment method
│   ├── Order/                      # PaymentReference — builds and parses the order reference sent to PayMongo
│   ├── Resolver/                   # GraphQL resolvers (PaymentMethods, CreateCheckoutSession, OrderPaymentStatus)
│   └── ViewModel/                  # CurrencyCheck view model for checkout currency notice
├── Observer/                       # CaptureOnShipment — triggers card capture when shipment is created
├── Plugin/
│   ├── Block/Adminhtml/Order/      # "Refresh from PayMongo" and "Capture from PayMongo" order buttons
│   └── Sales/                      # Refuses part-invoicing an order on a card hold
├── etc/                            # XML configuration (module, payment, routes, ACL, DI, GraphQL schema)
└── view/frontend/                  # Layout XML, templates, JS components (Luma checkout)
```

### Running tests

The module currently ships no automated tests — there is no `Test/` directory, and the
command previously documented here pointed at a path that does not exist. Changes are
verified manually against a Magento install (Luma checkout, sandbox credentials).

Adding unit coverage is worthwhile, starting with the pieces where a silent mistake is
most expensive: `Setup/Patch/Data/*` (one-way credential migration run during a merchant's
deploy), `Gateway/PaymongoClient::createCheckoutSession()` line-item and discount
arithmetic, and `Helper/Config` scope resolution. Once a `Test/Unit/` directory exists:

```bash
cd /path/to/magento
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/PayMongo/Payment/Test/
```

---

## Changelog

### 2.0.1

**Upgrading to 2.0.1 requires running `bin/magento setup:upgrade`.** The module's declared version changed, and Magento refuses to serve pages until the database records it. It also applies the one-off checkout order update described below; no schema change is applied.

- **First release as `paymongo/module-magento2-paymongo`, MIT-licensed.** The Magento module is `PayMongo_Payment` and the PHP namespace `PayMongo\Payment\`
- **QR Ph, Credit / Debit Card and GCash now come first at checkout**, with every other PayMongo method following in its previous order. This is the new default **Sort Order** (QR Ph 1, Card 2, GCash 3, then GrabPay, Maya, Google Pay, ShopeePay, BillEase, BPI and UnionBank). On upgrade, stores that saved the Payment Methods page without changing any Sort Order move to the new order automatically; a store, website or store view where any Sort Order was customised is left exactly as it is. The admin **Payment Methods** table lists the methods in the same default order
- **All-in-One Checkout** now orders its logos and the methods on PayMongo's hosted page by Sort Order too, instead of a fixed internal order — so it shows the same priority as Separate mode
- The **Paymongo Account Status** check now explains what to do about a method it reports as **Not Live-Ready**. Both badges carry a hover tooltip; the pending one points you at the PayMongo Dashboard in live mode → **Settings → Payment Methods** to request activation, notes that most methods need business documents submitted for approval and that only PayMongo can grant it, and confirms test mode keeps working while the request is pending — a pending badge is not a broken integration
- **Fixed: All-in-One Checkout failed when the Card method's Payment Action was Authorize Only.** All-in-One inherited the Card setting, narrowed PayMongo's page to card-only and requested a hold. On accounts without Hold Then Capture enabled, PayMongo rejected it with `organization_not_allowed`: the customer saw the raw error, the cart was emptied and an unpaid order was left behind. All-in-One Checkout now always uses Authorize & Capture and shows every enabled method; Authorize Only applies to the standalone Credit / Debit Card method only. All-in-One orders already on an authorisation hold when you upgrade are still captured when they ship. Present since 1.1.1
- Selecting **All-in-One Checkout** in the admin no longer resets the Card method's Payment Action to Authorize & Capture. The setting has no effect in All-in-One, and is kept for when you switch back to Separate mode
- **Fixed: an Authorize Only card order could ship without the customer being charged, and nobody was told.** When PayMongo did not capture the payment on shipment, the shipment saved with no invoice and the failure only reached the log, so the order looked shipped and normal. It is now put **On Hold** with a comment saying the customer has not been charged, and the admin sees an error when saving the shipment. Release the hold once the payment is sorted out in the PayMongo Dashboard. Present since 1.1.0
- **Fixed: an expired or cancelled card authorisation could be invoiced as paid.** A `resource_invalid_state` error from the capture was always read as "already captured", a workaround for test mode charging at checkout, so an authorisation PayMongo had dropped was invoiced and could reach Complete with no money taken. The module now asks PayMongo for the payment's status and only invoices one PayMongo reports as captured. Present since 1.1.0
- **Fixed: cancelling an Authorize Only card order left the customer's funds on hold** until PayMongo released them 7 days later. Cancelling now releases the hold on PayMongo and says so in the order comments, however the order is cancelled. Present since 1.1.0
- **Fixed: invoicing an Authorize Only order with Capture Online marked it paid without charging the card.** Capture Online only recorded a transaction, and shipping the order afterwards captured the hold anyway, even when it had been invoiced with Capture Offline for a payment collected another way. Capture Online now captures the hold through PayMongo, and an invoiced order is no longer captured on shipment. Present since 1.1.0
- **Fixed: an invoice for part of an Authorize Only order charged the whole order, or left the rest uncollected.** Only possible through the REST API or custom code, since the admin invoices these orders in full. With Capture Online the whole hold was captured for the part invoiced; with Capture Offline the order counted as invoiced, so shipping no longer captured the hold. These orders can now only be invoiced in full
- **Fixed: Authorize Only card orders could only be refunded offline.** The invoice created when the order shipped, or by **Capture from PayMongo**, recorded no PayMongo payment, so the credit memo offered only **Refund Offline** and the money had to be returned in the PayMongo Dashboard. These invoices now have a transaction ID and refund online like any other PayMongo payment. It is the PayMongo payment ID (`pay_…`), or the payment intent ID (`pi_…`) if PayMongo could not be asked for the payment when the invoice was created; the refund then looks the payment up. Orders captured before upgrading are not changed: refund them in the PayMongo Dashboard, then create a **Refund Offline** credit memo so the order matches. Present since 1.1.0
- **Online refunds now start from the invoice, as Magento's prompt says.** The order page's **Credit Memo** button warns "This will create an offline refund", but for orders of the legacy all-in-one method a plugin linked the order's invoice anyway and offered an online **Refund**, contradicting the prompt. That plugin (`CreditmemoLoaderPlugin`) is removed, so every PayMongo method behaves the same: from the order page, Refund Offline only; from the invoice (**Invoices** → the invoice → **Credit Memo**), **Refund** online. Only legacy all-in-one orders change
- **Removed the credit memo plugin that was meant to force the online Refund button** (`CreditmemoCreateItemsPlugin`). It hooked a method Magento never calls, so it has never run, on any version. Magento itself shows **Refund** for any invoice with a PayMongo transaction ID, which every invoice for a payment PayMongo took now has. No behaviour changes
- **Fixed: an online refund could be sent against a failed payment.** When the order had no PayMongo payment ID stored, the refund used the first payment PayMongo listed for it, paid or not, so an order whose customer had a failed attempt first (a declined card, say) could not be refunded online. It now uses the paid payment. The checkout session webhook had the same flaw when storing the payment ID, which is then never replaced, and now stores the paid payment too, like the customer's return to the store and Refresh from PayMongo already did
- **A refund PayMongo refuses now shows PayMongo's reason** on the credit memo page, such as "Refund amount is greater than the available payout amount", instead of PayMongo's raw API response. When the account's payout is too low for the refund, the message also says to top up the PayMongo balance and try again
- **Capture and refund now share one implementation for every PayMongo method** (`Model\Method\CaptureAndRefund`). The all-in-one method had its own copy, which had drifted. No behaviour changes, except that its minimum-refund error now reads ₱1.00 like the others. Cancelling an order now checks PayMongo once instead of once per step
- **All-in-One Checkout orders now appear in the audit log.** Captures and online refunds of orders paid with the all-in-one PayMongo method write `AUDIT:CAPTURE` and `AUDIT:REFUND` entries like every other method. Before, they wrote none
- **Fixed: a customer whose card was declined could pay for an order Magento had already cancelled** (PYM-32). A declined attempt cancelled the order straight away, but PayMongo's payment page stayed open, so a customer who retried with another card was charged while the order stayed Cancelled, with no invoice and no alert. Now:
  - A declined attempt keeps the order **Pending Payment**, with a comment giving PayMongo's reason, and a successful retry confirms and invoices it as normal.
  - A checkout the customer abandons is still cancelled: when they leave PayMongo's page, or by Magento's cron once the order is older than **Pending Payment Order Lifetime** (Stores → Configuration → Sales → Sales → Orders Cron Settings, 8 hours by default).
  - Cancelling a PayMongo order, however it happens, **closes its PayMongo payment page**, so no one can pay for a cancelled order.
  - If PayMongo still reports a payment, or a card hold, for a cancelled order, the admin gets a critical **System Message** and an "ACTION NEEDED" order comment, once per payment. Before, it was only a line in the log.
  - A webhook for another store's order with the same number (several installations on one PayMongo account) is ignored. Before, it could confirm or invoice this store's order
  - Opening the PayMongo payment page again (a refresh, a double click, or a second `createPaymongoCheckoutSession` call) closes the previous page first, so only one page can take a payment for an order. No page is opened for an order that is cancelled or already paid
  - A declined attempt's comment no longer counts as activity on the order, so repeated declines do not put off Magento's cron cancelling an abandoned checkout
  - A payment completed on an earlier PayMongo page of the order (the customer finished a 3DS check or e-wallet approval after a new page was opened) still confirms the order
  - When the order is busy, the payment webhook now asks PayMongo to send it again instead of skipping it, so a payment reported at that moment is not lost
  - `createPaymongoCheckoutSession` no longer returns PayMongo's raw API error to the storefront; the details stay in the PayMongo log
  - **GraphQL:** `createPaymongoCheckoutSession` now opens the page for the order's own payment method, and refuses a `method_code` that doesn't match it. Before, the `method_code` sent decided what PayMongo's page offered and whether the card was held, so a storefront could open a card page, even an Authorize Only one, for an order placed with GCash
  - **Every PayMongo method's orders now wait in Pending Payment.** Before, every method except Credit / Debit Card and All-in-One waited in **New** (status Pending), which Magento's stale-order cron never cancels, so abandoned GCash, Maya, QR Ph and other e-wallet or bank orders stayed open for good. Orders still open in **New** at the upgrade are moved to **Pending Payment** by `setup:upgrade`, each with a comment saying why, so the cron cancels them too once they have waited the full Pending Payment Order Lifetime from the upgrade
- **Fixed: anyone could cancel any order through the PayMongo cancel link.** `paymongo/payment/failed?order_id=…` cancelled whatever order ID it was given. It now only cancels the visitor's own order, the one their checkout just placed, and only a PayMongo order still awaiting payment. It also asks PayMongo first: an order that was paid, whose payment is still processing, or which is waiting on the customer's 3DS check or GCash / Maya approval when they used PayMongo's back link, is left for the payment to confirm instead of being cancelled. The cart is restored only when the order was cancelled
- **Fixed: a second partial refund of an invoice overwrote the first one's record.** Magento named every refund of an invoice `<payment ID>-refund`, so both credit memos carried the same transaction ID, and PayMongo's refund ID was stored nowhere. Each refund's transaction, and its credit memo, now carry PayMongo's refund ID (`ref_…`), which matches the PayMongo Dashboard
- **New: Capture from PayMongo button** on Authorize Only orders whose capture on shipment failed or could not be confirmed. It retries the capture, or invoices the order without charging again if PayMongo already took the payment. Once every item has shipped, no new shipment can trigger the capture, so previously the payment had to be collected outside Magento within the 7 days the hold lasts. Requires the **Sales → Operations → Orders → Actions → Capture** permission
- **Authorize Only orders now show when their card hold expires**, on the order page and the New Shipment page, with a warning in the last 2 days and an error once it has expired, so an order is not shipped after PayMongo can no longer charge for it
- **Fixed: BPI Online and UnionBank Online payments were refused** with `payment_method_not_allowed` ("Dob payment method on bpi is not allowed for your account") on accounts where PayMongo does not allow Direct Online Banking through its Payment Intent flow. Both now go through a checkout session like every other method: the customer picks the bank on PayMongo's hosted page, then signs in at the bank, instead of going straight to the bank's login page. UnionBank Online uses PayMongo's `dob_ubp` type. New BPI and UnionBank payments also carry the `MGN-…` reference number in the PayMongo dashboard
- **Removed the Direct Online Banking method.** It offered the same banks as **BPI Online** and **UnionBank Online** under a third, generic name, which confused customers at checkout. It is gone from checkout (Separate and All-in-One), the admin **Payment Methods** table, the **Paymongo Account Status** check and the `paymongoPaymentMethods` query, including on stores that had it enabled. Enable **BPI Online** and **UnionBank Online** to keep offering online banking. Orders already placed with Direct Online Banking still open, and can still be paid, invoiced and refunded
- **GraphQL:** `paymongo_type` (`paymongoPaymentMethods`) and `payment_method_type` (`createPaymongoCheckoutSession`) report UnionBank Online as `dob_ubp`, where they reported `dob` before. BPI Online still reports `dob`. A headless storefront that maps these values to logos or groups should add `dob_ubp`
- **PayMongo payments are now described as `Magento Order #MGN-000000015`** (was `Order #MGN-000000015`), so the PayMongo dashboard shows at a glance that a payment came from Magento and which order it belongs to. Refund notes follow suit: `Refund for Magento Order #MGN-000000015`
- **Payments from other systems on the same PayMongo account are now ignored.** Webhooks are matched only on the `MGN-…` reference this module sends. Previously an unprefixed `000000015` or `Order #000000015` from another app could mark Magento order 000000015 paid, or cancel it. A payment started on an earlier version and still unpaid at upgrade is no longer confirmed automatically; use **Refresh from PayMongo** on the order
- Transactions now carry a `metadata.plugin` of `magento_v2`, the key PayMongo uses for all its plugins, so it can attribute payments back to this integration. Applies to checkout sessions and refunds
- **Fixed: Refresh from PayMongo marked unpaid orders as paid.** A checkout still open on PayMongo, with the customer not yet paid, was treated as paid: the order moved to Processing, was invoiced against the payment intent ID (`pi_…`) and the confirmation email went out. Refresh now confirms an order only once PayMongo has its payment, using the same check as the customer's return to the store, and otherwise reports that PayMongo has not received payment yet. It also only acts on orders still awaiting payment (Pending or Pending Payment), and its button is no longer shown on cancelled, closed or completed orders. Present since 1.0
- **Fixed: shipping an Authorize Only order confirmed it a second time.** Capturing the card on shipment makes PayMongo send a payment webhook, and the webhook confirmed the order again even though it was already Complete: a misleading "Card authorised via PayMongo webhook" note and a second order confirmation email to the customer. The webhook and the customer's return to the store now confirm only orders still awaiting payment (Pending or Pending Payment). For any other order they only record the PayMongo payment ID, so a late webhook can also no longer move a cancelled order to Processing; a payment reported for a cancelled order is logged as a warning
- **Reconciliation now flags orders marked paid without a PayMongo payment** (**Not Paid in PayMongo**): a PayMongo order that is Processing or Complete in Magento, but that PayMongo has no payment for. Orders already wrongly confirmed by Refresh show up here, for you to cancel or refund offline. To keep one run within time limits, it checks at most 200 orders with PayMongo; past that, a **Check Incomplete** row says how many were not checked, so choose a shorter date range for those
- **Fixed: Reconciliation only saw the newest 100 or so PayMongo payments and refunds.** An older date range came back nearly empty, so its discrepancy checks missed real payments. Every payment and refund in the range is now fetched
- **docs/TROUBLESHOOTING.md** → `payment_method_not_allowed` now walks the activation steps, instead of only saying to check the dashboard and disable the method

### 1.1.3

- **Fixed: `setup:di:compile` failed on every store without Hyvä Checkout installed**, with `Class "Hyva\Checkout\Model\Magewire\Payment\AbstractPlaceOrderService" not found`. The Hyvä place-order service extends a Hyvä class, so its file cannot be loaded when Hyvä Checkout is absent — and the DI compiler loads every PHP file in every enabled module, regardless of whether anything uses it. Luma checkout itself was never affected at runtime — only compilation, which meant deploys to Luma-only stores could not complete
- **The Hyvä Checkout code moved out of this module**, so it no longer contains any class that depends on Hyvä Checkout
- No functional change to Luma checkout, admin, GraphQL, or the gateway. Blocks, templates, JS, CSS, and method icons all stay in this module and are unchanged

### 1.1.2

- **Fixed:** the **Payment from Applicable Countries** / **Payment from Specific Countries** restriction under General → Shared Credentials only ever applied to the all-in-one **PayMongo** method. Every individual method (Card, GCash, Maya, …) read its own unset per-method value instead and stayed available in every country, so in **Separate** display mode — the default — the restriction silently did nothing. All methods now read the shared setting
- New installs ship restricted to the **Philippines** (`allowspecific = 1`, `specificcountry = PH`) rather than offering PayMongo worldwide and failing at the gateway. Existing installs keep whatever is already saved; change it under General → Shared Credentials
- **Scope tightening — this narrows what 1.1.1 opened up.** Checkout Display, General Settings and Shared Credentials are no longer overridable per store view; default and website scope only. One credential set per website is the intended granularity, and a store view splitting off its own environment or keys is far more often a mistake than an intent. Within Payment Methods, only **Enabled**, **Title** and **Sort Order** stay store-editable — **Payment Action** is now default/website only
- **All-in-One Checkout** now removes the Title, Sort Order and Payment Action columns from the method table outright, headers included, instead of leaving three labelled but empty columns. Selecting All-in-One while Payment Action is on *Authorize Only* also moves it back to *Authorize & Capture*, since PayMongo cannot mix a card authorisation hold with other methods in one hosted session
- Method logos in the admin table are legible again — they were being squeezed to 8px wide by Magento's `img { max-width: 100% }` once the Title column claimed the row's spare width
- **Payment Action** options are now just *Authorize & Capture* and *Authorize Only*; the capture-timing explanation moved to a hover hint beside the field, so the label is no longer clipped

### 1.1.1

- Redesigned the **Payment Methods** admin page as a single flat table (Icon, Method, Enabled, Title, Sort Order, Payment Action) instead of one collapsible section per method
- **Separate Sandbox and Production credentials.** The single Secret Key / Public Key / Webhook Secret is replaced by one set per environment, plus an **Environment** selector that decides which set is used at checkout, for webhooks, and for every API call. Switching environments no longer means retyping keys. Existing credentials are migrated automatically on upgrade, based on whether the secret key is `sk_test_…` or `sk_live_…`
- Removed **Atome** — not actually supported by PayMongo
- All configuration fields — API keys, webhook secret, environment, master enable switch, order status, country restrictions, and checkout display settings — are now overridable per store view or website, not just per website (this required correcting webhook signature verification and `isAvailable()` to resolve keys/secrets from the right store scope instead of always checking default scope — otherwise a store or store view with its own credentials could see every PayMongo method disappear from checkout, or have its webhooks silently rejected)
- Added a **PayMongo** entry to the stock **Stores → Configuration → Sales → Payment Methods** grid, linking to the dedicated PayMongo configuration tab
- The "Check PayMongo Account Status" button reflects whichever store/website scope you're currently viewing in admin
- Reconciliation tool: discrepancies affecting the same PayMongo payment (e.g. a missing order and its related missing refund) are now grouped into a single row instead of two separate ones; discrepancy type labels are now human-readable (e.g. "Missing in Magento" instead of `MISSING_IN_MAGENTO`)
- **Fixed:** orders paid through **All-in-One Checkout** while the Card method's Payment Action was set to `Authorize Only` were being invoiced immediately instead of held for capture-on-shipment — the browser-return callback only recognized the direct Card method, not the all-in-one method. Both now behave identically.
- **Fixed:** an order could be invoiced twice, with `total_paid` double-counted and two confirmation emails sent, when PayMongo's webhook arrived while the customer's browser was returning to the success page. Both paths checked whether the order was already Processing, but nothing stopped them running that check at the same moment. The browser-return path now takes the same per-order lock the webhook already used, and re-reads the order once it holds it.
- **Fixed:** a returning customer whose PayMongo checkout session was never paid could land back on the success URL and have the order invoiced and a confirmation email sent for money that was never collected. The browser-return callback treated a session still sitting at `active` as successful; it now requires the payment to actually exist.
- **Fixed:** products sold by weight or length (decimal quantities) caused a fatal error on Place Order, leaving the order stranded in `pending_payment`. Line items with a quantity below 1 were rounded to zero and then divided by.
- **Fixed:** discounted line items could undercharge by a few centavos. PayMongo bills quantity × per-unit amount, so a discounted row total that did not divide evenly by its quantity lost the remainder — the order was invoiced in full while PayMongo collected slightly less, which then surfaced as an `AMOUNT_MISMATCH` in the reconciliation tool.
- **Fixed:** a webhook signed with a test-mode secret could be accepted by a store running in Production. The signature field is now bound to the configured environment, and the credential migration no longer copies one webhook secret into both environment slots.
- **Fixed:** on Luma checkout, a PayMongo method that was already selected — which Magento does automatically when it is the only method available, or when a customer returns from an abandoned redirect — rendered with no radio button and no Place Order button, leaving checkout unusable. The method group now counts as expanded whenever one of its methods is selected.
- **Fixed:** on Luma checkout, if the PayMongo method holding the shared group header stopped being available mid-session (for example a coupon dropping the total below its minimum order total), the header disappeared and every remaining PayMongo method stayed collapsed with no way to expand it. The header now passes to the next available method.
- The Luma group header is now keyboard-accessible (`Enter` / `Space`) and exposes `aria-expanded`.

### 1.1.0.0

- Added support for all PayMongo payment methods: GrabPay, Google Pay, ShopeePay, BillEase, Atome, BPI Online, UnionBank Online, QR Ph
- Each payment method now has its own Magento admin toggle (enable/disable per store)
- Luma (default Magento theme) checkout support — no Hyvä required
- Headless storefront support via GraphQL API (`paymongoPaymentMethods`, `createPaymongoCheckoutSession`, `paymongoOrderStatus`)
- Webhook signature verification and replay protection
- Authorize-now, capture-on-shipment for card payments
- Reconciliation tool (admin screen)
- Audit logging for refund and capture actions
- PayMongo request ID logged on every API call
- Non-PHP currency carts rejected with a visible notice at checkout
- Currency notice displayed above payment methods on Luma checkout
- Refund failure errors surfaced clearly in admin with retry support
- Legacy `paymongo` method currency guard (PHP only)
- Log file renamed to `var/log/payment.paymongo.log`

### 1.0.0

- Initial release
- Hosted checkout redirect flow
- Card, GCash, GrabPay, and Maya support
- Webhook integration
- Online refunds

---

## Support

For questions or issues, contact **support@devteam.com** or open an issue in the repository.
