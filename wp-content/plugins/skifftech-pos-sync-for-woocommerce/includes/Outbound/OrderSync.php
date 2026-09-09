<?php

declare(strict_types=1);

namespace PosSync\Outbound;

class OrderSync
{
    public static function register(): void
    {
        add_action('woocommerce_after_checkout_validation', [self::class, 'validateStockBeforeOrder'], 10, 2);
        add_action('woocommerce_order_status_processing', [self::class, 'pushOrderToPortal']);
    }

    /**
     * Validate stock with POS portal before order is placed.
     */
    public static function validateStockBeforeOrder($data, $errors): void
    {
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $quantity = (int) $cart_item['quantity'];

            if (!$product) {
                continue;
            }

            // SKU is safest from product object here
            $sku = $product->get_sku();
            if (empty($sku)) {
                continue;
            }

            // Call POS API
            $response = PortalClient::request(
                'GET',
                '/product-stock/check?itemCode=' . rawurlencode($sku) . '&requestQty=1',
                ['timeout' => 10]
            );

            if (is_wp_error($response)) {
                $errors->add(
                    'pos_api_error',
                    __('Unable to verify stock at the moment. Please try again.', 'pos-sync')
                );
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            $stockData = json_decode($body, true);

            $product_name = esc_html($product->get_name());
            $cart_url = wc_get_cart_url();

            if (!$stockData['data']) {
                // Not in our inventory
                $errors->add(
                    'pos_stock_error_' . $sku,
                    sprintf(
                        __('%s is not in our inventory. <a href="%s">Update your cart</a>.', 'pos-sync'),
                        $product_name,
                        esc_url($cart_url)
                    )
                );
                continue;
            }

            $available_qty = $stockData['data']['stock'];

            if ($available_qty < $quantity) {
                if ($available_qty <= 0) {

                    // Out of stock
                    $errors->add(
                        'pos_stock_error_' . $sku,
                        sprintf(
                            __('%s is currently out of stock. <a href="%s">Update your cart</a>.', 'pos-sync'),
                            $product_name,
                            esc_url($cart_url)
                        )
                    );

                } else {

                    // Limited stock
                    $errors->add(
                        'pos_stock_error_' . $sku,
                        sprintf(
                            __('Only %d item(s) available for %s. <a href="%s">Update your cart</a>.', 'pos-sync'),
                            $available_qty,
                            $product_name,
                            esc_url($cart_url)
                        )
                    );
                }
            }
        }
    }

    /**
     * Update POS Portal Stock when order is placed
     */
    public static function pushOrderToPortal($order_id): void
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            error_log('POS Sync: Order not found - ' . $order_id);
            return;
        }

        error_log('POS Sync: Preparing POS invoice for order - ' . $order_id);

        $items_payload = [];
        $total_amount  = 0;
        $total_discount = (float) $order->get_discount_total();
        $grand_total   = (float) $order->get_total();
        $paid_amount   = $grand_total;
        $due_amount    = 0;
        $payment_method = $order->get_payment_method();
        $order_status = $order->get_status();

        foreach ($order->get_items() as $item) {

            $product_id   = $item->get_product_id();
            $variation_id = $item->get_variation_id();

            $product = $variation_id
                ? wc_get_product($variation_id)
                : wc_get_product($product_id);

            if (!$product) {
                continue;
            }

            $sku = $product->get_sku();

            $quantity = (int) $item->get_quantity();
            $rate     = (float) $order->get_item_total($item, false);
            $amount   = $rate * $quantity;

            $total_amount += $amount;

            $items_payload[] = [
                'itemCode'          => $sku,
                'itemName'          => $item->get_name(),
                'quantity'          => $quantity,
                'rate'              => $rate,
                'amount'            => $amount,
                'unitName'          => 'PCS',
                'remainingQuantity' => null,
            ];
        }

        if (empty($items_payload)) {
            error_log('POS Sync: No valid items for order ' . $order_id);
            return;
        }

        // Build request body
        $request_body = [
            'invoiceNo'        => $order->get_order_number(),
            'date'             => $order->get_date_created()->date('Y-m-d'),
            'customerId'       => 0,
            'customerName'     => trim(
                $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
            ),
            'customerMobile'   => $order->get_billing_phone(),
            'totalAmount'      => $total_amount,
            'totalDiscount'    => $total_discount,
            'grandTotal'       => $grand_total,
            'paidAmount'       => $paid_amount,
            'dueAmount'        => $due_amount,
            'transactionMode'  => $payment_method,
            'status'           => $order_status,
            'remarks'          => 'OrderFromWebsite',
            'items'            => $items_payload,
        ];

        // POS API endpoint
        $response = PortalClient::request('POST', '/sales-order/create', [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body' => wp_json_encode($request_body),
        ]);
        if (is_wp_error($response)) {
            error_log(
                'POS Sync: API request failed - ' .
                $response->get_error_message()
            );
            return;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body        = wp_remote_retrieve_body($response);

        error_log(
            "POS Sync: API response ({$status_code}) for order {$order_id}"
        );
    }
}
