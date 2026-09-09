<?php
/**
 * Plugin Name: Skifftech POS Sync for WooCommerce
 * Description: Sync POS sales with WooCommerce using SKU. Inbound POS requests are authenticated via OAuth2 (Client Credentials + Refresh Token grants).
 * Version: 2.1.1
 * Requires Plugins: woocommerce
 * Text Domain: pos-sync
 */

if (!defined('ABSPATH')) exit;

$pos_sync_autoload = __DIR__ . '/vendor/autoload.php';

if (!file_exists($pos_sync_autoload)) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>' .
            esc_html__('Skifftech POS Sync: Composer dependencies are missing. Run "composer install" in the plugin directory.', 'pos-sync') .
            '</p></div>';
    });
    return;
}

require_once $pos_sync_autoload;

register_activation_hook(__FILE__, [PosSync\Activator::class, 'activate']);

add_action('rest_api_init', function () {
    PosSync\Rest\TokenController::register();
    PosSync\Rest\SaleController::register();
    PosSync\Rest\ProductController::register();
});

PosSync\Admin\ClientsPage::register();

/**
 * Outbound: WooCommerce -> POS portal, authenticated via OAuth2
 * (Client Credentials Grant) against the POS portal's own API.
 * See PosSync\Outbound\PortalClient / PosSync\Outbound\OrderSync.
 */
PosSync\Outbound\OrderSync::register();

/**
 * Outbound: refresh a product's stock/price from the POS portal whenever
 * its single product page is viewed. See PosSync\Outbound\ProductPageSync.
 */
PosSync\Outbound\ProductPageSync::register();
