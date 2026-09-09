<?php

declare(strict_types=1);

namespace PosSync\Rest;

use WC_Product_Simple;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Creates WooCommerce products from POS "product created" events.
 *
 * Expected JSON body (batch, mirrors the /sale endpoint shape):
 * {
 *   "items": [
 *     {
 *       "itemCode":          "SKU-123",     // required -> product SKU (unique)
 *       "itemName":          "Product",     // required -> product title
 *       "rate":              1200.00,        // optional -> regular price
 *       "salePrice":         999.00,         // optional -> sale price
 *       "description":       "Full text",    // optional
 *       "shortDescription":  "Short text",   // optional
 *       "remainingQuantity": 10,             // optional -> stock status only (>0 instock, else outofstock)
 *       "categoryName":      "Laptops"       // optional -> category (created if missing)
 *     }
 *   ]
 * }
 */
class ProductController
{
    private const MAX_ITEMS = 500;

    public static function register(): void
    {
        register_rest_route('pos-sync/v1', '/product', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle'],
            'permission_callback' => [Authentication::class, 'authenticate'],
        ]);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $client_id = $request->get_param('_oauth_client_id');
        error_log('POS Sync: Create product request received (client: ' . $client_id . ')');

        if (!class_exists('WooCommerce')) {
            error_log('POS Sync: Error - WooCommerce not active');
            return new WP_REST_Response(['error' => 'WooCommerce not active'], 500);
        }

        $data = $request->get_json_params();

        if (empty($data['items']) || !is_array($data['items'])) {
            error_log('POS Sync: Error - No items provided');
            return new WP_REST_Response(['error' => 'No items provided'], 400);
        }

        if (count($data['items']) > self::MAX_ITEMS) {
            error_log('POS Sync: Error - Batch too large (' . count($data['items']) . ' items)');
            return new WP_REST_Response(
                ['error' => 'Too many items in one request; maximum is ' . self::MAX_ITEMS . '. Split into smaller batches.'],
                400
            );
        }

        $created = [];
        $errors = [];

        foreach ($data['items'] as $item) {
            $result = self::createProduct($item);

            if (isset($result['error'])) {
                $errors[] = $result['error'];
                continue;
            }

            $created[] = $result;
        }

        $response = [
            'status'  => empty($errors),
            'created' => $created,
            'errors'  => $errors,
        ];
        error_log('POS Sync: Create product response - ' . wp_json_encode($response));

        return new WP_REST_Response($response, 200);
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private static function createProduct($item): array
    {
        if (!is_array($item) || empty($item['itemCode']) || empty($item['itemName'])) {
            return ['error' => 'Invalid product payload: itemCode and itemName are required'];
        }

        $sku = sanitize_text_field((string) $item['itemCode']);
        $name = sanitize_text_field((string) $item['itemName']);

        // Do not clobber an existing product; POS should use /sale to update stock.
        $existing_id = wc_get_product_id_by_sku($sku);
        if ($existing_id) {
            return ['error' => "Product already exists for ItemCode: {$sku} (product #{$existing_id})"];
        }

        $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_sku($sku);
        $product->set_status('publish');

        if (isset($item['rate'])) {
            $product->set_regular_price((string) (float) $item['rate']);
        }

        if (isset($item['salePrice']) && $item['salePrice'] !== '' && $item['salePrice'] !== null) {
            $product->set_sale_price((string) (float) $item['salePrice']);
        }

        if (isset($item['description'])) {
            $product->set_description(wp_kses_post((string) $item['description']));
        }

        if (isset($item['shortDescription'])) {
            $product->set_short_description(wp_kses_post((string) $item['shortDescription']));
        }

        if (isset($item['remainingQuantity']) && $item['remainingQuantity'] !== '' && $item['remainingQuantity'] !== null) {
            $product->set_stock_status((int) $item['remainingQuantity'] > 0 ? 'instock' : 'outofstock');
        } else {
            $product->set_stock_status('instock');
        }

        if (!empty($item['categoryName'])) {
            $category_id = self::resolveCategory(sanitize_text_field((string) $item['categoryName']));
            if ($category_id) {
                $product->set_category_ids([$category_id]);
            }
        }

        try {
            $product_id = $product->save();
        } catch (\Throwable $e) {
            error_log('POS Sync: Product save failed for ' . $sku . ' - ' . $e->getMessage());
            return ['error' => "Failed to create product for ItemCode: {$sku} - " . $e->getMessage()];
        }

        if (!$product_id) {
            return ['error' => "Failed to create product for ItemCode: {$sku}"];
        }

        error_log("POS Sync: Created product #{$product_id} for SKU {$sku}");

        return [
            'productId' => $product_id,
            'sku'       => $sku,
            'name'      => $name,
        ];
    }

    /**
     * Return an existing product category term id by name, creating it if missing.
     */
    private static function resolveCategory(string $name): ?int
    {
        $term = get_term_by('name', $name, 'product_cat');

        if ($term && !is_wp_error($term)) {
            return (int) $term->term_id;
        }

        $created = wp_insert_term($name, 'product_cat');

        if (is_wp_error($created)) {
            error_log('POS Sync: Failed to create category "' . $name . '" - ' . $created->get_error_message());
            return null;
        }

        return (int) $created['term_id'];
    }
}
