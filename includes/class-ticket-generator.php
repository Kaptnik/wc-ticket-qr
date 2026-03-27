<?php
/**
 * WCTQR_Generator
 *
 * Generates unique HMAC tokens for each ticket unit purchased
 * and persists them to the database when an order is paid.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WCTQR_Generator {

    public function __construct() {
        add_action( 'woocommerce_payment_complete',        [ $this, 'generate_tickets' ] );
        add_action( 'woocommerce_order_status_processing', [ $this, 'generate_tickets' ] );
        add_action( 'woocommerce_order_status_completed',  [ $this, 'generate_tickets' ] );
    }

    /**
     * Generate tokens for every ticket-product line item on an order.
     *
     * @param int $order_id
     */
    public function generate_tickets( $order_id ) {
        global $wpdb;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            if ( ! $product ) continue;

            // For variable products, _is_ticket is stored on the parent — check both
            $is_ticket = $product->get_meta( '_is_ticket' );
            if ( ! $is_ticket && $product->get_parent_id() ) {
                $parent    = wc_get_product( $product->get_parent_id() );
                $is_ticket = $parent ? $parent->get_meta( '_is_ticket' ) : '';
            }
            if ( ! $is_ticket ) continue;

            $quantity = $item->get_quantity();

            for ( $i = 0; $i < $quantity; $i++ ) {
                $token = $this->make_token( $order_id, $item_id, $i );

                // Skip if already exists (hook may fire more than once)
                $exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ticket_qr WHERE token = %s",
                    $token
                ) );
                if ( $exists ) continue;

                $wpdb->insert(
                    $wpdb->prefix . 'ticket_qr',
                    [
                        'order_id'      => $order_id,
                        'order_item_id' => $item_id,
                        'token'         => $token,
                    ],
                    [ '%d', '%d', '%s' ]
                );
            }
        }
    }

    /**
     * Deterministic HMAC token — cannot be forged without the server secret.
     *
     * @param  int $order_id
     * @param  int $item_id
     * @param  int $index     Zero-based index within the line-item quantity.
     * @return string         64-char hex string.
     */
    public function make_token( $order_id, $item_id, $index ) {
        $secret = defined( 'WCTQR_SECRET' ) ? WCTQR_SECRET : wp_salt( 'auth' );
        return hash_hmac( 'sha256', "{$order_id}:{$item_id}:{$index}", $secret );
    }
}

new WCTQR_Generator();
