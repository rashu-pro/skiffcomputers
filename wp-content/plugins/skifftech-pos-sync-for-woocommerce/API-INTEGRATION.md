# POS → WooCommerce Integration Guide

This document describes the REST API the POS system should call to push product creations and stock updates into the WooCommerce website. It's written for whoever implements the POS-side client.

## Base URL

```
https://<your-woocommerce-site>/wp-json/pos-sync/v1
```

Replace `<your-woocommerce-site>` with the real site domain. All endpoints below are relative to this base URL.

## 1. Authentication

All endpoints except the token endpoint itself require an OAuth2 **Bearer** access token. This is a standard OAuth2 **Client Credentials Grant**, with refresh token support.

### 1.1 Getting a client_id / client_secret

Credentials are **not** self-service from the POS side — the website admin generates them from **WP Admin → Tools → POS Sync Clients** and hands you a `client_id` and `client_secret` out of band. The secret is shown only once at creation time, so treat it like a password (store it in your POS system's secrets/config, never in source control or logs).

Each POS installation/environment should get its own client so it can be revoked independently without affecting others.

### 1.2 Requesting an access token

```
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials&client_id={client_id}&client_secret={client_secret}
```

**Response — 200 OK**
```json
{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
  "refresh_token": "def502000f8f4b1e4a..."
}
```

| Field | Meaning |
|---|---|
| `access_token` | JWT to send as `Authorization: Bearer {access_token}` on every API call. |
| `expires_in` | Access token lifetime in seconds (**3600** = 1 hour). |
| `refresh_token` | Use this to get a new access token without resending `client_secret`. Valid for **30 days**. |

**Response — 401 Unauthorized** (bad `client_id`/`client_secret`, or the client was revoked)
```json
{ "error": "invalid_client", "error_description": "Client authentication failed" }
```

### 1.3 Refreshing an access token

Do this when a call fails with 401 due to an expired access token, rather than requesting a brand-new token with `client_credentials` every time.

```
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=refresh_token&refresh_token={refresh_token}&client_id={client_id}&client_secret={client_secret}
```

Returns the same shape as above: a new `access_token` **and** a new `refresh_token`.

> **Important:** refresh tokens rotate on use — the old `refresh_token` is invalidated the moment you use it. Always store the *newest* `refresh_token` from the response; don't reuse an old one, it will fail with `invalid_grant`.

### 1.4 Recommended POS-side flow

1. On startup / first use, request a token with `client_credentials`.
2. Cache `access_token`, `refresh_token`, and the access token's expiry (`now + expires_in`).
3. Before each API call, if the cached access token is expired (or about to expire), refresh it using 1.3 instead of re-authenticating from scratch.
4. If any call returns `401`, refresh once and retry the call. If the refresh itself fails, fall back to a full `client_credentials` re-authentication (1.2).

## 2. Making API calls

All endpoints below:
- Require header: `Authorization: Bearer {access_token}`
- Accept and return `application/json`
- Accept a **batch** of items in a single call — send one item or many (up to **500 per call**), each is processed independently. A failure on one item doesn't block the others. Sending more than 500 items returns a `400` immediately with none of them processed — split larger syncs into multiple calls.

### 2.1 `POST /sale` — stock update

Call this whenever a POS sale changes a product's remaining stock.

**Request**
```json
{
  "items": [
    { "itemId": 1001, "itemCode": "SKU-123", "remainingQuantity": 0 },
    { "itemId": 1002, "itemCode": "SKU-456", "remainingQuantity": 12 }
  ]
}
```

| Field | Required | Type | Notes |
|---|---|---|---|
| `itemId` | yes | number/string | Your POS-side item ID, echoed back in the response for correlation. Not otherwise used. |
| `itemCode` | yes | string | Must match the product's SKU in WooCommerce exactly. |
| `remainingQuantity` | yes | number | Only the sign matters: `<= 0` sets the product **out of stock**, `> 0` sets it **in stock**. No exact quantity is stored on the WooCommerce side. |

**Response — 200 OK**
```json
{
  "status": true,
  "updated": [ { "itemId": 1001, "sku": "SKU-123" }, { "itemId": 1002, "sku": "SKU-456" } ],
  "errors": []
}
```

If some items fail (e.g. unknown SKU), `status` is `false`, successful items still appear in `updated`, and each failure is a human-readable string in `errors`:
```json
{
  "status": false,
  "updated": [ { "itemId": 1001, "sku": "SKU-123" } ],
  "errors": [ "Product not found for this ItemCode: SKU-999" ]
}
```

### 2.2 `POST /product` — create a new product

Call this when a new product is created in the POS, to mirror it into WooCommerce.

**Request**
```json
{
  "items": [
    {
      "itemCode": "SKU-789",
      "itemName": "Dell Inspiron 15",
      "rate": 1500.50,
      "salePrice": 1399,
      "description": "Full product description, HTML allowed.",
      "shortDescription": "Short summary, HTML allowed.",
      "remainingQuantity": 7,
      "categoryName": "Laptops"
    }
  ]
}
```

| Field | Required | Type | Notes |
|---|---|---|---|
| `itemCode` | yes | string | Becomes the WooCommerce SKU. **Must be unique** — if a product with this SKU already exists, this item is rejected (see below). Use `/sale` to update an existing product's stock instead. |
| `itemName` | yes | string | Product title. |
| `rate` | no | number | Regular price. |
| `salePrice` | no | number | Sale price. |
| `description` | no | string | Full description. Basic HTML is preserved. |
| `shortDescription` | no | string | Short description. Basic HTML is preserved. |
| `remainingQuantity` | no | number | Same rule as `/sale`: `> 0` → in stock, otherwise out of stock. Defaults to **in stock** if omitted. No exact quantity is stored. |
| `categoryName` | no | string | Matched by name against existing WooCommerce categories; created automatically if it doesn't exist yet. |

New products are created as **published** simple products immediately.

**Response — 200 OK**
```json
{
  "status": true,
  "created": [ { "productId": 4821, "sku": "SKU-789", "name": "Dell Inspiron 15" } ],
  "errors": []
}
```

**Duplicate SKU example** (product already exists — not overwritten):
```json
{
  "status": false,
  "created": [],
  "errors": [ "Product already exists for ItemCode: SKU-789 (product #4821)" ]
}
```

## 3. Error handling reference

| HTTP status | Meaning | What to do |
|---|---|---|
| `200` | Request processed (check `status`/`errors` for per-item results — a 200 does not guarantee every item succeeded). | Inspect `errors` array. |
| `400` | Malformed request — e.g. missing `items` array entirely, or more than 500 items in one call. | Fix the request body / split into smaller batches. |
| `401` | Missing/expired/invalid access token, or bad client credentials at the token endpoint. | Refresh or re-authenticate (see 1.4), then retry. |
| `500` | WooCommerce isn't active on the site, or an unexpected server error. | Retry later / alert an admin. |

Item-level failures (bad SKU, duplicate SKU, missing required field) are **not** HTTP errors — they come back as `200` with the failure described in the `errors` array, alongside any other items in the same batch that succeeded.

## 4. Quick test with curl

```bash
# 1. Get a token
curl -X POST "https://<your-woocommerce-site>/wp-json/pos-sync/v1/oauth/token" \
  --data-urlencode "grant_type=client_credentials" \
  --data-urlencode "client_id=YOUR_CLIENT_ID" \
  --data-urlencode "client_secret=YOUR_CLIENT_SECRET"

# 2. Push a stock update
curl -X POST "https://<your-woocommerce-site>/wp-json/pos-sync/v1/sale" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"items":[{"itemId":1,"itemCode":"SKU-123","remainingQuantity":0}]}'

# 3. Create a product
curl -X POST "https://<your-woocommerce-site>/wp-json/pos-sync/v1/product" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"items":[{"itemCode":"SKU-789","itemName":"Dell Inspiron 15","rate":1500.50}]}'
```

## 5. Notes / limitations

- There is currently no rate limiting on these endpoints, but batches are capped at 500 items per call — batch multiple items per call rather than calling once per item, but split large syncs (e.g. a full catalog import) into chunks of 500 or fewer.
- `/product` creates real WooCommerce products synchronously per item (including category lookups), so it's noticeably slower per item than `/sale`. For large product imports, prefer smaller batches (e.g. 50-100) over maxing out the 500 cap, so a single call doesn't tie up the connection for a long time.
- `/product` will never modify an existing product — it's create-only. Ongoing stock changes for existing products always go through `/sale`.
- If your `client_id` is revoked on the website side, it immediately stops being able to get **new** tokens (`client_credentials` or `refresh_token` both fail with `invalid_client`). Any access token issued *before* the revocation keeps working until it naturally expires (up to 1 hour) — revocation is not instant for already-issued tokens.
