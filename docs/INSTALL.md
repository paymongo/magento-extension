# Merchant Install Guide

**PayMongo for Magento 2 — v2.0.1**

This guide walks you through installing and configuring the PayMongo payment extension on your Magento 2 store. No developer experience required.

---

## Before you start

You will need:

- Admin access to your Magento store
- A PayMongo account — sign up at [dashboard.paymongo.com](https://dashboard.paymongo.com)
- Your PayMongo **API keys** (test or live)
- Your PayMongo **Webhook Signing Secret**

---

## Step 1 — Install the extension

Ask your developer to run the following commands on the server:

```bash
composer require paymongo/module-magento2-paymongo
bin/magento module:enable PayMongo_Payment
bin/magento setup:upgrade
bin/magento cache:flush
```

Once done, the extension is installed. You will configure it through the admin panel — no further server access is needed.

---

## Step 2 — Get your PayMongo API keys

1. Log in to [dashboard.paymongo.com](https://dashboard.paymongo.com)
2. Go to **Developers → API Keys**
3. Copy your **Secret Key** (starts with `sk_test_` for test mode or `sk_live_` for live)
4. Copy your **Public Key** (starts with `pk_test_` or `pk_live_`)

> **Test vs Live:** Use test keys while setting up. Switch to live keys only when you are ready to accept real payments.

---

## Step 3 — Set up your webhook

A webhook lets PayMongo notify your store when a payment is completed, even if the customer closes the browser.

1. In the PayMongo Dashboard, go to **Developers → Webhooks**
2. Click **Add Endpoint**
3. Enter your webhook URL:
   ```
   https://your-store.com/paymongo/payment/webhook
   ```
   *(Replace `your-store.com` with your actual domain)*
4. Enable these events:
   - `payment.paid`
   - `checkout_session.payment.paid`
   - `payment.failed`
5. Click **Save**
6. Click on the webhook you just created and copy the **Signing Secret**

Keep the Signing Secret — you will paste it into Magento in the next step.

---

## Step 4 — Configure the extension in Magento

1. Log in to your Magento admin panel
2. Go to **Stores → Configuration → PayMongo → General**
3. Expand the **Shared Credentials** group
4. Fill in the following fields:

| Field | What to enter |
|---|---|
| Environment | **Sandbox (Test Mode)** while you are testing, **Production (Live Mode)** when you go live. This decides which credential set below is actually used |
| Sandbox Secret Key | Your PayMongo test Secret Key (`sk_test_…`) |
| Sandbox Public Key | Your PayMongo test Public Key (`pk_test_…`) |
| Sandbox Webhook Secret | The Signing Secret from your **test-mode** webhook endpoint |
| Production Secret Key | Your PayMongo live Secret Key (`sk_live_…`) |
| Production Public Key | Your PayMongo live Public Key (`pk_live_…`) |
| Production Webhook Secret | The Signing Secret from your **live-mode** webhook endpoint |

> You only need to fill in the set for the environment you are using right now. The other set can stay empty until you need it — switching the Environment selector does not erase either set, so you can flip back and forth freely once both are filled in.

5. Click **Save Config**
6. Go to **System → Cache Management** and click **Flush Magento Cache**

---

## Step 5 — Enable your payment methods

Each PayMongo payment method has its own on/off switch. Go to **Stores → Configuration → PayMongo → Payment Methods**, where every method is listed as a row in a single table (Enabled, Title, Sort Order, and Payment Action columns — no need to expand anything to configure a method):

| Method | Notes |
|---|---|
| PayMongo — Credit/Debit Card | Accepts Visa and Mastercard |
| PayMongo — GCash | Philippines e-wallet |
| PayMongo — GrabPay | Philippines e-wallet |
| PayMongo — Google Pay | Requires customer to have Google Pay set up |
| PayMongo — Maya | Philippines e-wallet |
| PayMongo — ShopeePay | Philippines e-wallet |
| PayMongo — BillEase | Buy now, pay later |
| PayMongo — BPI Online | Direct online banking |
| PayMongo — UnionBank Online | Direct online banking |
| PayMongo — QR Ph | QR code payment |

For each method you want active:

1. Set **Enabled** to **Yes**
2. Optionally change the **Title** (what customers see at checkout)
3. Click **Save Config**

By default, each method you enable here appears as its own option at checkout. If you'd rather show a single "PayMongo" option that lists all of them together on one hosted page, set **Payment Display Mode** to **All-in-One Checkout** at the top of this same **Payment Methods** page, under **Checkout Display** — see the [Payment Display Options](../README.md#payment-display-options) section in the README.

---

## Step 6 — Test a payment

Before going live, place a test order using PayMongo's test card details.

1. Make sure you are using **test API keys** (`sk_test_…`)
2. Add any product to your cart and go to checkout
3. Select a PayMongo method (e.g. Credit/Debit Card)
4. Place the order — you will be redirected to PayMongo's checkout page
5. Use PayMongo's test card:
   - **Card number:** `4343 4343 4343 4345`
   - **Expiry:** any future date
   - **CVV:** any 3 digits
6. Complete the payment
7. You should be redirected back to your store's order success page
8. In Magento admin, check **Sales → Orders** — the order should be in **Processing** state with an invoice

---

## Step 7 — Go live

When you are ready to accept real payments:

1. In your PayMongo Dashboard, switch to **Live mode** and create a webhook endpoint pointing at your live store URL if it differs from your test one
2. In **Stores → Configuration → PayMongo → General → Shared Credentials**, fill in **Production Secret Key**, **Production Public Key**, and **Production Webhook Secret** (the Signing Secret from that live-mode endpoint)
3. Set **Environment** to **Production (Live Mode)**
4. Click **Save Config**, then flush the cache

> **Important:** the Production Webhook Secret is not optional. Each environment has its own signing secret, and the module verifies incoming webhooks against the one for the environment you have selected. Leave it empty and every live webhook is rejected, so paid orders will sit in Pending Payment.

> Your sandbox credentials are kept, so you can switch the Environment selector back to Sandbox for testing at any time without retyping anything.

---

## Managing orders

### Viewing PayMongo orders

Go to **Sales → Orders**. Orders paid via PayMongo will show the payment method name (e.g. "PayMongo — GCash") in the order details.

### Issuing a refund

1. Open the order
2. Click **Credit Memos** → **New Credit Memo**
3. Adjust quantities if doing a partial refund
4. Click **Refund** *(not "Refund Offline")*
5. The refund is sent to PayMongo immediately

Minimum refund amount: ₱1.00

### Order stuck in "Pending Payment"?

If a customer paid but the order shows Pending Payment (usually because the webhook was delayed):

1. Open the order in admin
2. Click **Refresh from PayMongo** in the top button bar
3. Magento will fetch the current payment status from PayMongo and update the order automatically

---

## Frequently asked questions

**Which currencies are supported?**
Philippine Peso (PHP) only. Carts in other currencies will not show PayMongo methods.

**Can I offer different methods on different stores?**
Yes. Each method can be enabled or disabled independently per store view.

**What PCI compliance tier does this extension require?**
SAQ A — the lightest tier. Card data is handled entirely by PayMongo and never passes through your server.

**What happens if a payment webhook is delayed?**
The order stays in Pending Payment until the webhook arrives. Use the **Refresh from PayMongo** button on the order view for immediate recovery.

---

## Need help?

Open a ticket on the [DevTeam PayMongo Support Portal](https://support-devteam-paymongo.zendesk.com/hc/en-us) (24/7) — it is also linked as **Devteam Support** at the top of every PayMongo configuration page — or email **support@devteam.com**, with your order number and the contents of `var/log/payment.paymongo.log`.
