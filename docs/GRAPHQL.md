# GraphQL API Reference

**PayMongo for Magento 2 — v1.1.3**

This reference is for engineers building headless Magento storefronts (Next.js, PWA, mobile apps) that need to integrate PayMongo payments via GraphQL.

**Endpoint:** `POST https://your-store.com/graphql`

---

## Overview

The PayMongo GraphQL API adds 2 queries and 1 mutation to Magento's standard GraphQL schema. Use them in combination with Magento's standard cart and order mutations:

```
Standard Magento mutations           PayMongo mutations
─────────────────────────            ────────────────────────────────────
addProductsToCart              →
setShippingAddressesOnCart     →
setShippingMethodsOnCart       →
setPaymentMethodOnCart         →     (use a paymongo_* method code)
placeOrder                     →     → returns order_number
                                     createPaymongoCheckoutSession
                                         → returns checkout_url
                               [Redirect customer to checkout_url]
                               [Customer pays on PayMongo's page]
                               [PayMongo redirects back to your app]
                                     paymongoOrderStatus (poll)
                                         → order_state = "processing"
```

---

## Authentication

Queries that return customer-specific data require a customer bearer token:

```
Authorization: Bearer <customer_token>
```

Obtain a token with Magento's standard mutation:

```graphql
mutation {
  generateCustomerToken(email: "customer@example.com", password: "password") {
    token
  }
}
```

---

## Queries

### `paymongoPaymentMethods`

Returns all PayMongo payment methods currently enabled for the resolved store. Use this to render the payment method list on the checkout payment step.

**Authentication:** Not required

**Request:**

```graphql
{
  paymongoPaymentMethods {
    code
    title
    paymongo_type
  }
}
```

**Response:**

```json
{
  "data": {
    "paymongoPaymentMethods": [
      {
        "code": "paymongo_gcash",
        "title": "GCash",
        "paymongo_type": "gcash"
      },
      {
        "code": "paymongo_card",
        "title": "Credit / Debit Card",
        "paymongo_type": "card"
      },
      {
        "code": "paymongo_maya",
        "title": "Maya",
        "paymongo_type": "paymaya"
      }
    ]
  }
}
```

**Fields:**

| Field | Type | Description |
|---|---|---|
| `code` | `String!` | Magento payment method code — use this as `method_code` in `setPaymentMethodOnCart` and `createPaymongoCheckoutSession` |
| `title` | `String!` | Customer-facing label configured in the Magento admin |
| `paymongo_type` | `String!` | PayMongo API `payment_method_type` value. BPI Online reports `dob`; UnionBank Online reports `dob_ubp` (earlier releases reported `dob`). Direct Online Banking (`paymongo_dob`, `dob`) is no longer listed as of 2.0.1 |

---

### `paymongoOrderStatus`

Returns the PayMongo payment status for a placed order. Poll this after redirecting the customer back from PayMongo to confirm payment before showing the order success screen.

**Authentication:** Required (customer token). The order must belong to the authenticated customer.

**Request:**

```graphql
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

**Variables:**

| Argument | Type | Description |
|---|---|---|
| `order_number` | `String!` | Magento order increment ID returned by `placeOrder` |

**Response:**

```json
{
  "data": {
    "paymongoOrderStatus": {
      "order_increment_id": "000000055",
      "order_state": "processing",
      "session_status": "paid",
      "payment_id": "pay_abc123",
      "payment_intent_id": "pi_xyz789"
    }
  }
}
```

**Fields:**

| Field | Type | Description |
|---|---|---|
| `order_increment_id` | `String!` | Magento order increment ID |
| `order_state` | `String!` | Magento order state (`pending_payment`, `processing`, `canceled`, etc.) |
| `session_status` | `String` | PayMongo checkout session status (`active`, `paid`, `expired`). Null if order is already `processing` (no additional API call is made in that case) |
| `payment_id` | `String` | PayMongo payment ID (`pay_…`) if payment was captured |
| `payment_intent_id` | `String` | PayMongo payment intent ID (`pi_…`) |

**Polling guidance:**

Poll every 2–3 seconds for up to 60 seconds after redirect. Stop when `order_state = "processing"` or after the timeout.

```javascript
async function waitForPayment(orderNumber, token) {
  for (let i = 0; i < 20; i++) {
    const result = await graphql(ORDER_STATUS_QUERY, { order_number: orderNumber }, token);
    if (result.paymongoOrderStatus.order_state === 'processing') {
      return result.paymongoOrderStatus;
    }
    await new Promise(r => setTimeout(r, 3000));
  }
  throw new Error('Payment confirmation timed out');
}
```

---

## Mutations

### `createPaymongoCheckoutSession`

Creates a PayMongo hosted checkout session for a cart that has already had its order placed. Returns a `checkout_url` to redirect the customer to.

**Authentication:** Required (customer token)

**Important:** Call `placeOrder` first. This mutation expects the order to already exist — it looks up the most recent order for the given cart.

**Request:**

```graphql
mutation CreatePaymongoSession($cartId: String!, $methodCode: String!) {
  createPaymongoCheckoutSession(input: {
    cart_id: $cartId
    method_code: $methodCode
  }) {
    session_id
    checkout_url
    payment_method_type
  }
}
```

**Variables:**

```json
{
  "cartId": "gZYiKRA8j0j5l1bd2Is1zyyiuBA5joJc",
  "methodCode": "paymongo_gcash"
}
```

**Input fields:**

| Field | Type | Description |
|---|---|---|
| `cart_id` | `String!` | Masked cart ID from `customerCart { id }` |
| `method_code` | `String!` | PayMongo method code from `paymongoPaymentMethods` or known code |

**Response:**

```json
{
  "data": {
    "createPaymongoCheckoutSession": {
      "session_id": "cs_jPGXjLtBKkxVdnJuTpJFnD5c",
      "checkout_url": "https://checkout.paymongo.com/cs_jPGXjLtBKkxVdnJuTpJFnD5c/checkout",
      "payment_method_type": "gcash"
    }
  }
}
```

**Fields:**

| Field | Type | Description |
|---|---|---|
| `session_id` | `String!` | PayMongo checkout session ID (`cs_…`) |
| `checkout_url` | `String!` | URL to redirect the customer to — opens PayMongo's hosted checkout page |
| `payment_method_type` | `String!` | The method's PayMongo type, the same value `paymongoPaymentMethods` reports as `paymongo_type`, or `multi` for the all-in-one method |

---

## Complete headless checkout example

```graphql
# 1. Get cart ID
query GetCartId {
  customerCart { id }
}

# 2. Add item
mutation AddItem($cartId: String!) {
  addProductsToCart(
    cartId: $cartId
    cartItems: [{ quantity: 1, sku: "MY-SKU-001" }]
  ) {
    cart { items { quantity } }
  }
}

# 3. Set shipping address
mutation SetShipping($cartId: String!) {
  setShippingAddressesOnCart(input: {
    cart_id: $cartId
    shipping_addresses: [{
      address: {
        firstname: "Juan"
        lastname: "dela Cruz"
        street: ["123 Rizal St"]
        city: "Makati"
        region: "Metro Manila"
        postcode: "1200"
        country_code: "PH"
        telephone: "09171234567"
      }
    }]
  }) {
    cart {
      shipping_addresses {
        available_shipping_methods { carrier_code method_code label amount { value } }
      }
    }
  }
}

# 4. Set shipping method (use carrier_code + method_code from step 3)
mutation SetShippingMethod($cartId: String!) {
  setShippingMethodsOnCart(input: {
    cart_id: $cartId
    shipping_methods: [{ carrier_code: "flatrate", method_code: "flatrate" }]
  }) {
    cart { shipping_addresses { selected_shipping_method { carrier_code } } }
  }
}

# 5. Set payment method
mutation SetPayment($cartId: String!) {
  setPaymentMethodOnCart(input: {
    cart_id: $cartId
    payment_method: { code: "paymongo_gcash" }
  }) {
    cart { selected_payment_method { code } }
  }
}

# 6. Place order
mutation PlaceOrder($cartId: String!) {
  placeOrder(input: { cart_id: $cartId }) {
    order { order_number }
  }
}

# 7. Create PayMongo checkout session
mutation CreateSession($cartId: String!) {
  createPaymongoCheckoutSession(input: {
    cart_id: $cartId
    method_code: "paymongo_gcash"
  }) {
    checkout_url
  }
}

# → Redirect customer to checkout_url

# 8. Poll order status (repeat until order_state = "processing")
query OrderStatus($orderNumber: String!) {
  paymongoOrderStatus(order_number: $orderNumber) {
    order_state
    session_status
  }
}
```

---

## Error handling

GraphQL errors are returned in the standard `errors` array:

```json
{
  "errors": [
    {
      "message": "No order found for this cart. Please call placeOrder first.",
      "category": "graphql-no-such-entity"
    }
  ]
}
```

Common errors:

| Message | Cause |
|---|---|
| `cart_id and method_code are required` | Missing input fields |
| `Cart not found` | Invalid or expired cart ID |
| `No order found for this cart. Please call placeOrder first.` | `placeOrder` was not called before this mutation |
| `Failed to create PayMongo checkout session` | PayMongo API error — check `var/log/payment.paymongo.log` |
| `Customer is not authenticated` | Missing or invalid bearer token |
| `Order does not belong to the current customer` | Token belongs to a different customer |

---

## Support

Contact **support@devteam.com** with:
- The GraphQL query or mutation you ran
- The full error response
- The relevant entries from `var/log/payment.paymongo.log` (look for `paymongo_request_id`)
