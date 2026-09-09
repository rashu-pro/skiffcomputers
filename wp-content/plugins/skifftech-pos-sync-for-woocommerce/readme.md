# Skifftech POS Sync for WooCommerce

Two-way sync between the Skiff Computers POS system and WooCommerce, keyed on product SKU.

- **POS → WooCommerce**: create products and push stock updates via an OAuth2-secured REST API.
- **WooCommerce → POS**: verify live stock at checkout and push completed orders to the POS portal as invoices.

## Requirements

- WordPress with WooCommerce active
- PHP >= 8.1 with the `openssl` and `sodium` extensions
- [Composer](https://getcomposer.org/) (to install PHP dependencies — see Installation)

## Installation

1. Copy the plugin into `wp-content/plugins/skifftech-pos-sync-for-woocommerce`.
2. Install dependencies:
   ```
   cd wp-content/plugins/skifftech-pos-sync-for-woocommerce
   composer install --no-dev
   ```
3. Activate the plugin from **Plugins**. On activation it will:
   - create three database tables for OAuth clients/tokens,
   - generate an RSA keypair and an encryption key under `wp-content/uploads/pos-sync-keys/` (protected by a deny-all `.htaccess`).

If `vendor/` is missing, the plugin shows an admin notice and does nothing else until `composer install` has been run.

## Authenticating (OAuth2, Client Credentials + Refresh Token)

The inbound REST endpoints (`/sale`, `/product`) require an OAuth2 Bearer access token. There is no shared static key — each POS/integration gets its own revocable client.

### 1. Create a client

Go to **WP Admin → Tools → POS Sync Clients**, enter a name, and click **Generate client**. The `client_id` and `client_secret` are shown **once** — store them securely, they cannot be viewed again. From the same screen you can revoke a client at any time, which immediately invalidates its ability to get new tokens (existing access tokens also stop validating once revoked).

### 2. Get an access token

```
POST /wp-json/pos-sync/v1/oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials&client_id=<client_id>&client_secret=<client_secret>
```

Response:
```json
{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "eyJ...",
  "refresh_token": "def502..."
}
```

- `access_token` is a JWT, valid for 1 hour.
- `refresh_token` is valid for 30 days and can be exchanged for a new access token without resending the client secret.

### 3. Refresh a token

```
POST /wp-json/pos-sync/v1/oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=refresh_token&refresh_token=<refresh_token>&client_id=<client_id>&client_secret=<client_secret>
```

Returns a new `access_token` **and** a new, rotated `refresh_token`. The old refresh token is revoked immediately — reusing it fails with `invalid_grant`.

### 4. Call the API

```
Authorization: Bearer <access_token>
```

## Endpoints

### `POST /wp-json/pos-sync/v1/sale` — update stock from POS sales

```json
{
  "items": [
    { "itemId": 1, "itemCode": "SKU-123", "remainingQuantity": 0 }
  ]
}
```

- Looks up the WooCommerce product by SKU (`itemCode`).
- Sets stock status to `outofstock` if `remainingQuantity <= 0`, otherwise `instock`. No stock quantity number is written, only the status.
- `itemId`, `itemCode`, and `remainingQuantity` are required per item.

Response:
```json
{
  "status": true,
  "updated": [ { "itemId": 1, "sku": "SKU-123" } ],
  "errors": []
}
```

### `POST /wp-json/pos-sync/v1/product` — create a product from POS

```json
{
  "items": [
    {
      "itemCode":          "SKU-123",
      "itemName":          "Product name",
      "rate":              1500.50,
      "salePrice":         1399,
      "description":       "Full description",
      "shortDescription":  "Short description",
      "remainingQuantity": 7,
      "categoryName":      "Laptops"
    }
  ]
}
```

- `itemCode` and `itemName` are required; everything else is optional.
- Creates a published simple product. **Will not overwrite an existing SKU** — if the SKU already exists, that item is reported as an error and skipped (use `/sale` to update stock on existing products).
- `remainingQuantity` only sets stock status (`instock`/`outofstock`, same rule as `/sale`) — no quantity is stored.
- `categoryName` is matched by name against existing WooCommerce product categories, creating it if it doesn't exist.

Response:
```json
{
  "status": true,
  "created": [ { "productId": 123, "sku": "SKU-123", "name": "Product name" } ],
  "errors": []
}
```

Both endpoints accept a batch of up to 500 items and process each independently — a failure on one item (bad payload, duplicate SKU) doesn't stop the rest; per-item failures are reported in `errors` and `status` is `false` if any occurred. Sending more than 500 items in one call returns a `400` before anything is processed.

## Outbound sync (WooCommerce → POS)

These run automatically once configured. They call out to the POS portal's own API — a separate OAuth2 relationship from the inbound layer above (there, this site is the OAuth2 *server*; here, it's the *client* authenticating to the POS portal).

**Configuration** — add to `wp-config.php` (in the "Add any custom values" section):
```php
define( 'POS_SYNC_OUTBOUND_BASE_URL', 'https://pos-test.skiffcomputers.com/api/v1' );
define( 'POS_SYNC_OUTBOUND_CLIENT_ID', 'skiff-pos-api-client' );
define( 'POS_SYNC_OUTBOUND_CLIENT_SECRET', '<client secret from the POS portal>' );
```

`PosSync\Outbound\PortalClient` (`includes/Outbound/PortalClient.php`) handles the OAuth2 Client Credentials Grant against `{base_url}/oauth/token`, caches the access token in a transient until shortly before it expires, and transparently retries once with a fresh token if a call comes back `401`. If credentials aren't configured (or the portal token request fails), calls fail gracefully — the site keeps working, the outbound call is just skipped with an error logged.

- **Checkout stock check** (`woocommerce_after_checkout_validation`): before an order is placed, each cart item's SKU is checked against `{base_url}/product-stock/check`. Checkout is blocked with a cart-editing prompt if the item isn't in POS inventory or the requested quantity exceeds available stock, or if the POS portal can't be reached/authenticated.
- **Order push** (`woocommerce_order_status_processing`): when an order moves to *Processing*, its line items and totals are posted to `{base_url}/sales-order/create` as an invoice.

## Uninstalling

Deactivating the plugin does nothing destructive — it just stops the hooks/routes. **Deleting** the plugin from the Plugins screen runs `uninstall.php`, which permanently removes:

- the three OAuth database tables (all clients and tokens),
- the RSA keypair and encryption key (`wp-content/uploads/pos-sync-keys/`).

This is irreversible — any existing client credentials and issued tokens are gone. Re-installing generates a fresh keypair and starts with no clients; you'll need to create new ones from the admin screen.

## Testing

See the Postman flow: get a token via `/oauth/token` (`x-www-form-urlencoded` body), then call `/sale` or `/product` (JSON body) with `Authorization: Bearer <access_token>`.

## Project layout

```
skifftech-pos-sync-for-woocommerce.php   Bootstrap: hooks, route registration, outbound sync functions
uninstall.php                            Runs on plugin deletion
includes/
  Activator.php                          Creates tables + keys on activation
  Uninstaller.php                        Drops tables + keys on deletion
  Tables.php                             DB table name helpers
  Keys.php                               RSA/encryption key generation & storage
  Psr7Bridge.php                         WP_REST_Request/Response <-> PSR-7 bridge for league/oauth2-server
  Admin/ClientsPage.php                  Tools → POS Sync Clients admin screen
  Rest/
    Authentication.php                   Shared OAuth2 bearer-token permission callback
    TokenController.php                  POST /oauth/token
    SaleController.php                   POST /sale
    ProductController.php                POST /product
  OAuth/
    ServerFactory.php                    Builds League's AuthorizationServer / ResourceServer
    Grant/ClientCredentialsRefreshGrant.php   Client Credentials grant that also issues a refresh token
    Repositories/                        League repository interfaces backed by the plugin's DB tables
    Entities/                            League entity implementations (Client, AccessToken, RefreshToken, Scope)
  Outbound/
    PortalClient.php                     OAuth2 client-credentials client for calling the POS portal's own API
```
