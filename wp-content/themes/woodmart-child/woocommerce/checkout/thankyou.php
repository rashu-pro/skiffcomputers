<?php
/**
 * Thankyou page
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/thankyou.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce/Templates
 * @version 3.7.0
 */

defined( 'ABSPATH' ) || exit;

$wrapper_classes = '';

if ( woodmart_get_opt( 'thank_you_page_extra_content' ) ) {
	$wrapper_classes .= ' wd-with-extra-content';
}

?>

<div class="woocommerce-order<?php echo esc_attr( $wrapper_classes ); ?>">

    <div class="thanks-wrapper">
        <?php if ( $order ) : ?>

            <?php do_action( 'woocommerce_before_thankyou', $order->get_id() ); ?>

            <?php if ( $order->has_status( 'failed' ) ) : ?>
                <div class="order-failed">
                    <p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed"><?php esc_html_e( 'Unfortunately your order cannot be processed as the originating bank/merchant has declined your transaction. Please attempt your purchase again.', 'woocommerce' ); ?></p>

                    <p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed-actions">
                        <a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" class="button pay"><?php esc_html_e( 'Pay', 'woocommerce' ); ?></a>
                        <?php if ( is_user_logged_in() ) : ?>
                            <a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" class="button pay"><?php esc_html_e( 'My account', 'woocommerce' ); ?></a>
                        <?php endif; ?>
                    </p>
                </div>

                <div class="billing-details">
                    <?php if ( woodmart_get_opt( 'thank_you_page_default_content' ) ) : ?>
                        <?php do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() ); ?>
                        <?php do_action( 'woocommerce_thankyou', $order->get_id() ); ?>
                    <?php endif; ?>
                </div>



            <?php else : ?>
                <div class="row">
                    <div class="col-md-8 thanks-wrapper-left">
                        <div class="thanks-wrapper-left-wrapper">
                            <?php if ( woodmart_get_opt( 'thank_you_page_extra_content' ) || woodmart_get_opt( 'thank_you_page_html_block' ) ) : ?>
                                <div class="wd-order-extra-content">
                                    <?php if ( 'text' === woodmart_get_opt( 'thank_you_page_content_type', 'text' ) ) : ?>
                                        <?php echo do_shortcode( woodmart_get_opt( 'thank_you_page_extra_content' ) ); ?>
                                    <?php else : ?>
                                        <?php echo woodmart_get_html_block( woodmart_get_opt( 'thank_you_page_html_block' ) ); ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ( woodmart_get_opt( 'thank_you_page_default_content' ) ) : ?>
                                <div class="success-icon">
                                    <img src="<?php echo home_url() ?>/wp-content/uploads/2022/01/check_mark.png" class="img-fluid">
                                </div>
                                <h3 class="thanks-title"><?php echo apply_filters( 'woocommerce_thankyou_order_received_text', esc_html__( 'Thank you. Your order has been received.', 'woocommerce' ), $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3>

                                <?php do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() ); ?>

                                <div class="order-confirmation-details">
                                    <?php do_action( 'woocommerce_thankyou', $order->get_id() ); ?>
                                </div>

                            <?php endif; ?>

                        </div>
                    </div>

                    <div class="col-md-4 thanks-wrapper-right">
                        <div class="thanks-wrapper-right-wrapper">
                            <?php if ( woodmart_get_opt( 'thank_you_page_default_content' ) ) : ?>
                                <ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">

                                    <li class="woocommerce-order-overview__order order">
                                        <?php esc_html_e( 'Order number:', 'woocommerce' ); ?>
                                        <strong><?php echo $order->get_order_number(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong>
                                    </li>

                                    <li class="woocommerce-order-overview__date date">
                                        <?php esc_html_e( 'Date:', 'woocommerce' ); ?>
                                        <strong><?php echo wc_format_datetime( $order->get_date_created() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong>
                                    </li>

                                    <?php if ( is_user_logged_in() && $order->get_user_id() === get_current_user_id() && $order->get_billing_email() ) : ?>
                                        <li class="woocommerce-order-overview__email email">
                                            <?php esc_html_e( 'Email:', 'woocommerce' ); ?>
                                            <strong><?php echo $order->get_billing_email(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong>
                                        </li>
                                    <?php endif; ?>

                                    <li class="woocommerce-order-overview__total total">
                                        <?php esc_html_e( 'Total:', 'woocommerce' ); ?>
                                        <strong><?php echo $order->get_formatted_order_total(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong>
                                    </li>

                                    <?php if ( $order->get_payment_method_title() ) : ?>
                                        <li class="woocommerce-order-overview__payment-method method">
                                            <?php esc_html_e( 'Payment method:', 'woocommerce' ); ?>
                                            <strong><?php echo wp_kses_post( $order->get_payment_method_title() ); ?></strong>
                                        </li>
                                    <?php endif; ?>

                                </ul>
                                <?php do_action( 'woocommerce_thankyou', $order->get_id() ); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <?php endif; ?>


        <?php else : ?>

            <div class="success-icon">
                <img src="<?php echo home_url() ?>/wp-content/uploads/2022/01/check_mark.png" class="img-fluid">
            </div>

            <h3 class="thanks-title"><?php echo apply_filters( 'woocommerce_thankyou_order_received_text', esc_html__( 'Thank you. Your order has been received.', 'woocommerce' ), null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3>

        <?php endif; ?>
    </div>



</div>
