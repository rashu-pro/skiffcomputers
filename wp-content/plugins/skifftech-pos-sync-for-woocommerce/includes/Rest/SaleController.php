<?php

declare(strict_types=1);

namespace PosSync\Rest;

use WP_REST_Request;
use WP_REST_Response;

class SaleController
{
    private const MAX_ITEMS = 500;

    public static function register(): void
    {
        register_rest_route('pos-sync/v1', '/sale', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle'],
            'permission_callback' => [Authentication::class, 'authenticate'],
        ]);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $client_id = $request->get_param('_oauth_client_id');
        error_log('POS Sync: Handle sale request received (client: ' . $client_id . ')');

        if (!class_exists('WooCommerce')) {
            error_log('POS Sync: Error - WooCommerce not active');
            return new WP_REST_Response(['error' => 'WooCommerce not active'], 500);
        }

        $data = $request->get_json_params();
        error_log('POS Sync: Request received - ' . wp_json_encode($data));

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

        $updated = [];
        $errors = [];

        foreach ($data['items'] as $item) {
            error_log('POS Sync: Processing item ID ' . ($item['itemId'] ?? 'UNKNOWN'));

            if (!isset($item['itemId']) || !isset($item['itemCode']) || !isset($item['remainingQuantity'])) {
                $error_msg = 'Invalid item payload';
                error_log('POS Sync: ' . $error_msg);
                $errors[] = $error_msg;
                continue;
            }

            $itemId = $item['itemId'];
            $sku = $item['itemCode'];

            $product_id = wc_get_product_id_by_sku($sku);

            if (!$product_id) {
                $error_msg = "Product not found for this ItemCode: {$sku}";
                error_log('POS Sync: ' . $error_msg);
                $errors[] = $error_msg;
                continue;
            }

            $remaining_qty = intval($item['remainingQuantity']);
            $product = wc_get_product($product_id);

            // Update stock status based on remaining quantity
            if ($remaining_qty <= 0) {
                $product->set_stock_status('outofstock');
            } else {
                $product->set_stock_status('instock');
            }

            // Save product
            $product->save();

            $updated[] = [
                'itemId' => $itemId,
                'sku' => $sku,
            ];
        }

        $response = [
            'status' => (empty($errors) ? true : false),
            'updated' => $updated,
            'errors' => $errors
        ];
        error_log('POS Sync: Response - ' . wp_json_encode($response));

        return new WP_REST_Response($response, 200);
    }
}
