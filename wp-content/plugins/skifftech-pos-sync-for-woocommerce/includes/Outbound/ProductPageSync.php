<?php

declare(strict_types=1);

namespace PosSync\Outbound;

/**
 * Syncs a product's stock status (in stock / out of stock) and price from the
 * POS portal whenever a visitor loads that product's single product page,
 * using the same `/product-stock/check` endpoint OrderSync uses to validate
 * stock at checkout. No stock quantity number is written, only the status.
 */
class ProductPageSync
{
    /**
     * Minimum time between POS lookups for the same SKU, so repeated page
     * views don't hit the POS portal on every request.
     */
    private const THROTTLE_SECONDS = 60;
    private const THROTTLE_TRANSIENT_PREFIX = 'pos_sync_pdp_';

    public static function register(): void
    {
        add_action('wp', [self::class, 'syncOnProductView']);
    }

    public static function syncOnProductView(): void
    {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        $post_id = get_queried_object_id();
        if (!$post_id) {
            return;
        }

        $product = wc_get_product($post_id);
        if (!$product) {
            return;
        }

        $sku = $product->get_sku();
        if (empty($sku)) {
            return;
        }

        $throttle_key = self::THROTTLE_TRANSIENT_PREFIX . md5($sku);
        if (get_transient($throttle_key)) {
            return;
        }
        set_transient($throttle_key, 1, self::THROTTLE_SECONDS);

        $response = PortalClient::request(
            'GET',
            '/product-stock/check?itemCode=' . rawurlencode($sku) . '&requestQty=1',
            ['timeout' => 5]
        );

        if (is_wp_error($response)) {
            error_log('POS Sync: Product page stock check failed for SKU ' . $sku . ' - ' . $response->get_error_message());
            return;
        }

        if (wp_remote_retrieve_response_code($response) !== 200) {
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['success']) || empty($body['data'])) {
            return;
        }

        self::applyToProduct($product, $body['data']);
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function applyToProduct(\WC_Product $product, array $item): void
    {
        $dirty = false;

        if (isset($item['stock']) && is_numeric($item['stock'])) {
            // Managed stock would show the old tracked quantity number instead of a plain status.
            if ($product->get_manage_stock()) {
                $product->set_manage_stock(false);
                $dirty = true;
            }

            $stock_status = (float) $item['stock'] > 0 ? 'instock' : 'outofstock';

            if ($product->get_stock_status() !== $stock_status) {
                $product->set_stock_status($stock_status);
                $dirty = true;
            }
        }

        if (isset($item['salesRate']) && is_numeric($item['salesRate'])) {
            $rate = (string) $item['salesRate'];

            if (self::normalizePrice($product->get_regular_price()) !== self::normalizePrice($rate)) {
                $product->set_regular_price($rate);
                $dirty = true;
            }
        }

        if ($dirty) {
            $product->save();
        }
    }

    private static function normalizePrice(string $price): string
    {
        if ($price === '') {
            return '';
        }

        return is_numeric($price) ? number_format((float) $price, 2, '.', '') : trim($price);
    }
}
