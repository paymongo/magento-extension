# Security Guide

**PayMongo for Magento 2 — v1.1.3**

---

## PCI compliance scope

### What applies to you (SAQ A)

Card data — card number, expiry, CVV — is entered directly on PayMongo's hosted checkout page and processed entirely by PayMongo. It never passes through your Magento server, is never stored in your database, and is never visible to your code.

This means your store qualifies for **PCI DSS SAQ A** — the lightest self-assessment questionnaire, requiring no on-site audit and no penetration testing. The key requirement is that your checkout page is served over HTTPS, which any production Magento store already satisfies.

### What must NOT change

To keep your SAQ A status:

- Do not embed a payment form that collects card fields directly on your Magento checkout page. The extension's redirect-to-PayMongo approach is what maintains SAQ A.
- Do not log, store, or transmit PayMongo API responses that include card data. The extension only logs order IDs, amounts, and session/payment IDs — never card numbers or CVVs.
- Do not remove or bypass the Content Security Policy headers declared in `etc/csp_whitelist.xml`.

---

## API key management

### Storage

API keys (Secret Key and Public Key) are stored encrypted in the Magento database using Magento's built-in encryptor (`Magento\Framework\Encryption\EncryptorInterface`). They are decrypted in memory only at the moment of an API call and are never written to logs.

### Rotation

To rotate your PayMongo API keys:

1. Generate new keys in the [PayMongo Dashboard](https://dashboard.paymongo.com) under **Developers → API Keys**
2. In Magento admin, go to **Stores → Configuration → PayMongo → General**
3. Replace the **Secret Key** and **Public Key** fields with the new values
4. Click **Save Config** and flush the Magento cache

Old keys can be revoked in the PayMongo Dashboard once the new keys are confirmed working.

### Test vs live keys

Never use test keys (`sk_test_…`) in a production environment or live keys (`sk_live_…`) in a development environment. Mixing them causes silent payment failures that are difficult to diagnose.

---

## Webhook security

### Signature verification

Every incoming webhook request from PayMongo is verified using HMAC-SHA256 before any order processing occurs. The verification compares the `Paymongo-Signature` header against an expected signature derived from:

- The raw request body
- The webhook signing secret stored in Magento config, for the currently configured environment
- A timestamp extracted from the signature header

Requests without a valid signature are rejected with a `400` response and logged.

**Environment binding (v1.1.1+):** PayMongo sends the test-mode HMAC in the signature header's `te` field and the live-mode one in `li`. The module reads only the field matching the configured Environment — `te` in Sandbox, `li` in Production — so a signature produced with a test-mode secret can never authorise an event on a store running in Production, even if that store's Production webhook secret were mistakenly set to the test value. Relatedly, the v1.1.1 credential migration never copies a webhook secret into both environment slots for exactly this reason.

**Store scope (v1.1.1+):** if you configure different webhook secrets or environments per store or website, the correct one is resolved automatically before verification — the controller does a read-only lookup of the order referenced in the payload to determine its store, then checks that store's secret (falling back to default scope if the order can't be resolved yet). This lookup never trusts the payload for anything beyond picking which secret to check the signature against; the HMAC comparison itself is unaffected.

### Replay protection

The signature header contains a timestamp. Requests with a timestamp older than **5 minutes** are rejected, regardless of whether the signature is valid. This prevents an attacker from capturing a valid signed webhook and replaying it later to mark an order as paid a second time.

### Webhook secret rotation

If your webhook signing secret is compromised:

1. In the [PayMongo Dashboard](https://dashboard.paymongo.com), go to **Developers → Webhooks**
2. Delete the existing webhook endpoint
3. Create a new webhook endpoint with the same URL
4. Copy the new **Signing Secret**
5. In Magento admin, paste the new signing secret into **Stores → Configuration → PayMongo → General → Shared Credentials**, into the field for the environment you regenerated it in — **Sandbox Webhook Secret** or **Production Webhook Secret**
6. Save and flush the cache

> Each environment has its own signing secret and its own field. Rotating the live secret does not affect the sandbox one, and vice versa.

> Deleting and recreating the webhook endpoint is the only way PayMongo provides to generate a new signing secret.

---

## Content Security Policy

The extension declares the domains it communicates with in `etc/csp_whitelist.xml`:

| Directive | Domain | Purpose |
|---|---|---|
| `connect-src` | `https://api.paymongo.com` | Server-to-server API calls |
| `frame-src` | `https://checkout.paymongo.com` | PayMongo hosted checkout (iframes) |
| `script-src` | `https://assets.paymongo.com` | PayMongo JS assets |

Magento enforces these policies in production mode. Any attempt to load scripts or make requests to undeclared domains from the checkout page will be blocked by the browser. This limits the blast radius of an XSS vulnerability in a third-party module — it cannot exfiltrate PayMongo data to an attacker's server.

---

## Admin access control

The extension defines two ACL resources:

| Resource | What it grants |
|---|---|
| `PayMongo_Payment::config` | Full read/write access to PayMongo configuration (API keys, webhook secret, method settings) |
| `PayMongo_Payment::view` | Read-only access to PayMongo configuration — suitable for finance staff who need to verify settings without the ability to change them |
| `PayMongo_Payment::reconciliation` | Access to the PayMongo Reconciliation admin screen |

Assign roles in **System → Permissions → User Roles**. Finance staff should be given `view` only — never `config` unless they need to rotate keys.

---

## Audit logging

All privileged admin actions are logged to `var/log/payment.paymongo.log` with the `AUDIT:` prefix. Each entry includes:

- The action type (`REFUND` or `CAPTURE`)
- The Magento order ID
- The amount
- The PayMongo payment or payment intent ID
- The admin username who performed the action (or `system` for automated actions such as webhooks)

To search audit entries:

```bash
grep "AUDIT:" var/log/payment.paymongo.log
```

Example entry:

```
[2026-06-20 09:14:33] paymongo.INFO: AUDIT:REFUND {"order_id":"000000055","actor":"admin.user","amount":"1500.00","paymongo_payment_id":"pay_abc123"} []
```

Audit logs should be retained according to your organization's compliance requirements. For PCI DSS, a minimum of 12 months is recommended, with at least 3 months immediately available.

---

## Dependency security

The extension has no direct third-party PHP dependencies beyond Magento's bundled libraries (`Magento\Framework\HTTP\Client\Curl`, `Magento\Framework\Serialize\Serializer\Json`). All PayMongo API communication uses PHP's built-in cURL via Magento's wrapper.

To audit the extension's transitive dependencies:

```bash
composer audit
```

---

## Reporting security issues

Do not report security vulnerabilities via public GitHub issues. Email **security@devteam.com** with:

- A description of the vulnerability
- Steps to reproduce
- Your assessment of impact and severity

We aim to acknowledge reports within 48 hours and provide a fix timeline within 5 business days.
