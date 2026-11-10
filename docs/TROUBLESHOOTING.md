# Troubleshooting Guide

**PayMongo for Magento 2 — v1.1.3**

Indexed by PayMongo error code and common symptom.

---

## How to find the error

All API calls log to `var/log/payment.paymongo.log`. Open it and search for the relevant order ID or error:

```bash
grep "order_id.*000000055" var/log/payment.paymongo.log
grep "ERROR" var/log/payment.paymongo.log | tail -20
```

When contacting PayMongo support, always include the `paymongo_request_id` from the log entry — it is the first thing their support team needs.

---

## PayMongo API error codes

### `resource_invalid_state`

**Symptom:** Refund or capture fails with "resource_invalid_state".

**Cause:** The payment or payment intent is in a state that does not allow the requested operation. Common scenarios:
- Trying to refund a payment that was already fully refunded
- Trying to capture a payment intent that was already captured or voided

**Resolution:**
- For refunds: check the PayMongo Dashboard to confirm the payment status. If already refunded, no action is needed in Magento — manually mark the credit memo as refunded offline.
- For captures: the authorization window may have expired (PayMongo voids authorizations after a set number of days). The module checks the payment intent's status after a failed capture: if PayMongo had already captured it, the order is invoiced as normal; otherwise the order is put on hold — see [Authorize-capture: order On Hold after shipping](#authorize-capture-order-on-hold-after-shipping). Contact PayMongo support with the `paymongo_request_id` to confirm.

---

### `authentication_failure` / `unauthorized`

**Symptom:** All PayMongo API calls fail. Orders are not confirmed. Refunds fail immediately.

**Cause:** The Secret Key configured in Magento is invalid, missing, or does not match the environment (test key used with live webhook or vice versa).

**Resolution:**

1. Go to **Stores → Configuration → PayMongo → General**
2. Verify the Secret Key matches the environment (test vs live)
3. Re-enter the key and save
4. Flush the cache

---

### `resource_not_found`

**Symptom:** "PayMongo API error: resource_not_found" in logs.

**Cause:** The checkout session ID or payment ID stored on the order no longer exists in PayMongo. This can happen if:
- The session expired before the customer paid (PayMongo sessions expire after 24 hours)
- The order was created against a test environment and you switched to live keys

**Resolution:**
- If the session expired: the order should be cancelled. Cancel it in Magento admin and ask the customer to place a new order.
- If it was a test/live key mismatch: cancel the order in Magento and ensure your keys match your environment.

---

### `invalid_amount` / amount below minimum

**Symptom:** Refund fails with amount error.

**Cause:** The refund amount is below PayMongo's minimum of **₱1.00** (100 centavos).

**Resolution:** Ensure the refund amount is at least ₱1.00. For small partial refunds, consider combining with other items or issuing a manual refund outside Magento and marking the credit memo as refunded offline.

---

### Refund amount is greater than the available payout amount

**Symptom:** An online refund fails with "PayMongo did not refund this payment: Refund amount is greater than the available payout amount". The same refund in the PayMongo Dashboard fails the same way.

**Cause:** PayMongo pays refunds out of the account's pending payout, and keeps its fee on every payment, even one that is refunded. On a new account, or one with few recent payments, the pending payout can be lower than the refund. For example, a ₱37.00 payment with a ₱16.30 fee leaves ₱20.70, so refunding the full ₱37.00 is ₱16.30 short. Waiting does not help: once the payout is sent to the bank, nothing is left to refund from.

**Resolution:** Top up the PayMongo balance (PayMongo Dashboard → **Top Up**) by at least the shortfall, then retry the refund from the invoice's credit memo. Nothing was refunded and no credit memo was saved by the failed attempt, so retrying does not refund twice. Or ask PayMongo support to process the refund.

---

### `payment_method_not_allowed`

**Symptom:** A specific payment method (e.g. BillEase) is shown but returns an API error when the customer tries to pay.

**Cause:** The method is enabled in Magento but your PayMongo account does not have that method activated. Some PayMongo methods (BillEase, QR Ph) require separate application and approval from PayMongo.

**Resolution:**

1. In Magento admin, open **Stores → Configuration → PayMongo → Payment Methods** and click **Check Paymongo Account Status**. Every method PayMongo has not approved for live transactions is badged **Not Live-Ready** next to its title — hover the badge for what PayMongo still needs from you
2. Log in to the [PayMongo Dashboard](https://dashboard.paymongo.com) in **Live mode** and open **Settings → Payment Methods** to request the method. Most methods need business documents submitted for approval, and the documents vary by your entity type; PayMongo activates the method once they have been reviewed — nothing in Magento can move this along
3. Disable the method in Magento admin until your account has it activated. Test mode is unaffected while you wait, so you can keep testing the method against sandbox credentials

---

### `organization_not_allowed` when paying by card

**Symptom:** Paying by Credit / Debit Card fails with `organization_not_allowed`, and the order is left in Pending Payment.

**Cause:** The Card method's **Payment Action** is **Authorize Only**, which asks PayMongo to hold the amount instead of charging it, and your PayMongo account does not have **Hold Then Capture** enabled.

On versions before 2.0.1, this also broke **All-in-One Checkout**, which wrongly inherited the Card setting. From 2.0.1 All-in-One always charges immediately and is not affected.

**Resolution:**

1. Ask PayMongo to enable Hold Then Capture on your account, or
2. Set **Stores → Configuration → PayMongo → Payment Methods → Credit / Debit Card → Payment Action** to **Authorize & Capture** (Separate mode shows the field)

---

### `webhook_invalid_signature` / `400` on webhook endpoint

**Symptom:** Orders stay in Pending Payment. Logs show "Webhook signature verification failed" or no webhook entries at all.

**Cause:** The Webhook Secret in Magento does not match the signing secret in the PayMongo Dashboard.

**Resolution:**

1. In Magento admin, check **Stores → Configuration → PayMongo → General → Shared Credentials** and note which **Environment** is selected
2. In the PayMongo Dashboard, switch to the matching mode (Test or Live) and open **Developers → Webhooks → [your endpoint]**
3. Copy the **Signing Secret**
4. Paste it into the field for that same environment — **Sandbox Webhook Secret** or **Production Webhook Secret**
5. Save and flush cache

**If this started right after upgrading to v1.1.1, or right after switching Environment:** v1.1.1 split the single webhook secret into one per environment. Because a webhook secret carries no prefix identifying which mode it belongs to, the upgrade migrates it into only the slot for the environment your secret key indicated, and deliberately leaves the other empty. So the first time you switch to an environment you have not used before, its webhook secret field is blank and every incoming webhook is rejected until you fill it in. Follow the steps above for the environment you just switched to.

If the issue persists, check that the webhook URL is accessible from the internet (not behind a VPN or localhost) and that there are no firewall rules blocking PayMongo's IP ranges.

---

## Common symptoms

### Order stuck in "Pending Payment"

**Most common cause:** Webhook was not delivered (network issue, incorrect webhook URL, or missing webhook secret).

**Steps to diagnose:**

1. Check `var/log/payment.paymongo.log` for any webhook entries around the time the customer paid
2. Check the PayMongo Dashboard → **Developers → Webhooks → [your endpoint] → Recent Deliveries** — look for failed delivery attempts
3. Use the **Refresh from PayMongo** button on the order view in Magento admin to manually fetch and apply the payment status

**If webhook deliveries are failing:** Fix the webhook configuration (see `webhook_invalid_signature` above), then use the Refresh button to recover already-stuck orders.

**If the order has a "payment attempt was declined" comment:** this is expected. A declined card no longer cancels the order, because the customer can still pay on PayMongo's page. If they never do, Magento's cron cancels the order once it is older than **Pending Payment Order Lifetime** (Stores → Configuration → Sales → Sales → Orders Cron Settings, 8 hours by default), and PayMongo's page is closed. The cron only cancels orders whose status is **pending_payment**. PayMongo orders get that status unless a custom order status has been assigned to the Pending Payment state; in that case, cancel abandoned orders by hand or assign the status back. Orders placed before 2.0.1 with methods other than Credit / Debit Card and All-in-One waited in **New** (status Pending), which the cron never cancels; `setup:upgrade` moves the ones still open to Pending Payment, with a comment, so the cron handles them too.

---

### "ACTION NEEDED: PayMongo took the customer's payment … but the order is cancelled"

**Symptom:** A critical System Message in the admin, and this comment on a cancelled order.

**Cause:** PayMongo took a payment (or holds a card) for an order Magento had already cancelled, for example a payment that was still processing when the order was cancelled, or a payment page that could not be closed. The customer has paid for an order the store will not fulfil.

**Resolution:** Refund the payment in the PayMongo Dashboard (or cancel the payment, for a card hold), or contact the customer and place a new order for them. The order comment names the payment.

---

### PayMongo dashboard reference numbers don't match my Magento order numbers

**Symptom:** An order is `000000015` in Magento but appears as `MGN-000000015` in PayMongo, or a saved dashboard filter or export that searched by exact order number stopped returning rows.

**Cause:** Every order sent to PayMongo carries the fixed `MGN-` prefix so Magento transactions can be identified in a PayMongo account that also takes payments from other channels. Magento's order numbers are not affected — only what is sent to PayMongo. There is no admin setting to change or clear it; the prefix is a constant in the module (`Model\Order\PaymentReference::PREFIX`).

**Resolution:**

1. Search the PayMongo dashboard for the order number without the prefix — it matches as a partial reference
2. Orders paid before the prefix was introduced keep the bare order number they were sent with
3. BPI Online and UnionBank Online orders placed before they moved to Checkout Sessions carry no reference number (PayMongo's Payment Intent flow has no such field) — look for those in the description instead

---

### Payment methods not showing at checkout

**Possible causes:**

1. **Cart currency is not PHP.** PayMongo only supports PHP. Switch the store currency to Philippine Peso.
2. **Method is disabled.** Go to **Stores → Configuration → PayMongo → Payment Methods** and enable the method.
3. **API key is wrong.** The `isAvailable()` check may be failing. Check `var/log/payment.paymongo.log` for errors.
4. **Cache not flushed.** After any configuration change, flush the Magento cache.

---

### "Unable to redirect to payment gateway" at checkout

**Cause:** The Redirect controller caught an error creating the checkout session. The specific error is in `var/log/payment.paymongo.log`.

**Common reasons:**
- API key is invalid (see `authentication_failure`)
- Cart currency is not PHP (fixed in v1.1.0.0 — should now show a clear error before this point)
- PayMongo API is temporarily unavailable

Check the log for the entry with `paymongo_request_id` and contact PayMongo support if the error is on their side.

---

### Refund button not showing on credit memo

**Cause:** The credit memo page shows only "Refund Offline" and not the "Refund" button.

Magento offers the online **Refund** only for a credit memo on an invoice that has a transaction ID, which for PayMongo is the payment (`pay_…`) or payment intent (`pi_…`). A credit memo opened from the order page is not linked to an invoice, so it is always offline; Magento's prompt on that button says so.

**Resolution:**

1. Open the credit memo from the invoice you want to refund: the order's **Invoices** tab → the invoice → **Credit Memo**
2. If the invoice itself has no transaction ID, it cannot be refunded online. That is the case for an invoice created with **Capture Offline**, and for an Authorize Only card order shipped before 2.0.1, whose invoice was created without one. Refund the payment in the PayMongo Dashboard, then create a **Refund Offline** credit memo so the order matches. Refund it in one place only: never both online from Magento and in the Dashboard
3. Flush the Magento cache

---

### Authorize-capture: order in Processing but not charged

**Cause:** The card was authorised but not yet captured. The capture happens automatically when a shipment is created.

**Resolution:** Create a shipment for the order in Magento admin (**Sales → Orders → [Order] → Ship**). The `CaptureOnShipment` observer will call the PayMongo API to capture the payment and create the invoice.

If the shipment was created but capture still did not happen, check `var/log/payment.paymongo.log` for `CAPTURE` entries and any errors.

---

### Authorize-capture: order On Hold after shipping

**Symptom:** Saving a shipment shows "PayMongo did not capture the payment for order #…", "the PayMongo capture for order #… failed and it could not be confirmed whether the customer was charged", or "PayMongo captured the payment for order #…, but the invoice could not be created", and the order is **On Hold** with no invoice.

**Cause:** The capture failed when the shipment was created. The order comment names the payment intent and its status. If the comment says it could not be confirmed whether the customer was charged, PayMongo could not be reached or the payment was still processing: the money may have been taken, so check before collecting it another way. Usually the card authorisation expired (PayMongo releases an uncaptured hold after 7 days) or was cancelled; it can also be a declined capture, or PayMongo not answering.

**Resolution:**
1. Click **Capture from PayMongo** on the order. It asks PayMongo to take the held money, then releases the hold and invoices the order. If PayMongo had already taken the payment, it invoices the order without charging again. The button needs the **Sales → Operations → Orders → Actions → Capture** permission
   If the comment says PayMongo captured the payment but the invoice could not be created, the customer HAS been charged: the button only creates the invoice
2. If it reports that the card is no longer held, the authorisation expired or was cancelled: collect payment from the customer another way, then click **Unhold** and create the invoice with **Capture Offline**
3. If it reports that it still could not confirm whether the customer was charged, wait a few minutes and try again. Do not collect payment another way until PayMongo confirms the customer was not charged

Details of the failed call are in `var/log/payment.paymongo.log`, under `CaptureOnShipment`.

---

### Authorize-capture: cancelled order still holds the customer's funds

**Symptom:** Cancelling an order shows "its card authorisation could not be released on PayMongo" or "PayMongo could not be checked for a card hold on it".

**Cause:** The order was cancelled in Magento, but PayMongo refused to cancel the payment intent, or could not be reached to check whether there was a hold at all. Many orders, such as abandoned Pending Payment ones, never had a hold.

If the comment instead says PayMongo has already captured the customer's payment, the money was taken although Magento has no invoice (a capture that could not be confirmed or invoiced at the time). Refund the customer through PayMongo.

**Resolution:** Look the payment intent up in the PayMongo Dashboard. If it is still **awaiting capture**, the funds are held until PayMongo releases them 7 days after authorisation, unless the hold is cancelled through PayMongo first. Details are in `var/log/payment.paymongo.log`, under `ReleaseHoldOnCancel`.

---

### Authorize-capture: invoice refused with "PayMongo can only capture that payment once"

**Symptom:** Creating an invoice through the REST API (or custom code) fails, with "Could not save an invoice, see error log for details" from the API. The log says the order "was paid by card with Authorize Only, and PayMongo can only capture that payment once, for the full order amount".

**Cause:** The invoice was for only part of the order while PayMongo still holds the card. PayMongo captures a card hold once, and the module always captures all of it, so until then these orders are invoiced in full. Nothing was charged and nothing was saved. If the message instead says "This invoice is for only part of order", it came from Capture Online on an order that was already part-invoiced: collect the rest another way and invoice it with Capture Offline.

**Resolution:** Invoice the whole order at once (leave out `items` in `POST /V1/order/{id}/invoice`), or ship it and let the capture on shipment invoice it.

---

### GraphQL: `createPaymongoCheckoutSession` returns "No order found for this cart"

**Cause:** The `placeOrder` mutation was not called before `createPaymongoCheckoutSession`, or was called with a different cart ID.

**Resolution:** Always call `placeOrder` first and use the same `cart_id`. See the [GraphQL reference](GRAPHQL.md) for the correct mutation sequence.

---

### Reconciliation tool shows discrepancies

**Paid PayMongo transaction with no Magento invoice:**
- The webhook was not delivered and the Refresh button was not used
- Use the order view's **Refresh from PayMongo** button to recover

**Refund in PayMongo with no Magento credit memo:**
- A refund was issued directly in the PayMongo Dashboard, bypassing Magento
- Create a credit memo in Magento marked as "Refund Offline" to match the records

**Mismatched amounts:**
- A partial refund amount may differ due to rounding (PayMongo uses centavos, integer arithmetic)
- Check `var/log/payment.paymongo.log` for the exact amounts sent to PayMongo

---

## Getting support

Before contacting support, collect:

1. The Magento order increment ID (e.g. `000000055`)
2. The relevant log lines from `var/log/payment.paymongo.log` — especially the `paymongo_request_id`
3. The PayMongo Dashboard → Webhooks → Recent Deliveries for the relevant webhook endpoint
4. The Magento and PHP versions: `bin/magento --version` and `php -v`

Open a ticket on the [DevTeam PayMongo Support Portal](https://support-devteam-paymongo.zendesk.com/hc/en-us) (24/7), or email **support@devteam.com**, with the above. If the issue is on PayMongo's side, share the `paymongo_request_id` when opening a ticket with PayMongo support — it allows them to find the exact API call in their system.
