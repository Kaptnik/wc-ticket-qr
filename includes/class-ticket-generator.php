<?php
/**
 * WCTQR_Generator
 *
 * per_ticket mode: one token per unit purchased (original behaviour)
 * per_order mode:  one token for the entire order, covering all ticket line items
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WCTQR_Generator {

    public function __construct() {
        add_action( 'woocommerce_payment_complete',        [ $this, 'generate_tickets' ] );
        add_action( 'woocommerce_order_status_processing', [ $this, 'generate_tickets' ] );
        add_action( 'woocommerce_order_status_completed',  [ $this, 'generate_tickets' ] );
    }

    public function generate_tickets( $order_id ) {
        global $wpdb;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        if ( WCTQR_Settings::is_per_order_mode() ) {
            $this->generate_order_token( $order );
        } else {
            $this->generate_per_ticket_tokens( $order );
        }
    }

    /**
     * Per-order mode: one single token for the whole order.
     * Stores a JSON summary of all ticket line items.
     */
    private function generate_order_token( $order ) {
        global $wpdb;

        $order_id = $order->get_id();

        // Collect all ticket line items
        $ticket_items = $this->get_ticket_items( $order );
        if ( empty( $ticket_items ) ) return;

        // One token keyed to the order, not any specific item
        $token = $this->make_token( $order_id, 0, 0 );

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ticket_qr WHERE token = %s", $token
        ) );
        if ( $exists ) return;

        // Total admits = sum of all ticket quantities
        $total_qty = array_sum( array_column( $ticket_items, 'quantity' ) );

        $wpdb->insert(
            $wpdb->prefix . 'ticket_qr',
            [
                'order_id'           => $order_id,
                'order_item_id'      => 0,
                'token'              => $token,
                'quantity'           => $total_qty,
                'order_item_summary' => wp_json_encode( $ticket_items ),
            ],
            [ '%d', '%d', '%s', '%d', '%s' ]
        );
    }

    /**
     * Per-ticket mode: one token per unit.
     */
    private function generate_per_ticket_tokens( $order ) {
        global $wpdb;

        $order_id = $order->get_id();

        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            if ( ! $product ) continue;

            if ( ! $this->item_is_ticket( $product ) ) continue;

            $quantity = (int) $item->get_quantity();

            for ( $i = 0; $i < $quantity; $i++ ) {
                $token  = $this->make_token( $order_id, $item_id, $i );
                $exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ticket_qr WHERE token = %s", $token
                ) );
                if ( $exists ) continue;

                $wpdb->insert(
                    $wpdb->prefix . 'ticket_qr',
                    [
                        'order_id'      => $order_id,
                        'order_item_id' => $item_id,
                        'token'         => $token,
                        'quantity'      => 1,
                    ],
                    [ '%d', '%d', '%s', '%d' ]
                );
            }
        }
    }

    /**
     * Build a structured summary of all ticket line items in an order.
     * Returns array of items, each with name, quantity, variation attributes.
     */
    public function get_ticket_items( $order ) {
        $items = [];

        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            if ( ! $product || ! $this->item_is_ticket( $product ) ) continue;

            $qty  = (int) $item->get_quantity();
            $name = $item->get_name(); // WC includes variation in the name

            // Build attributes array for variations
            $attributes = [];
            if ( $product->is_type( 'variation' ) ) {
                $parent = wc_get_product( $product->get_parent_id() );
                if ( $parent ) {
                    $name = $parent->get_name(); // use parent name, attrs separate
                }
                foreach ( $product->get_variation_attributes() as $key => $value ) {
                    $label = wc_attribute_label( str_replace( 'attribute_', '', $key ), $product );
                    if ( taxonomy_exists( str_replace( 'attribute_', '', $key ) ) ) {
                        $term = get_term_by( 'slug', $value, str_replace( 'attribute_', '', $key ) );
                        if ( $term ) $value = $term->name;
                    }
                    $attributes[ $label ] = $value;
                }
            }

            $items[] = [
                'item_id'    => $item_id,
                'name'       => $name,
                'quantity'   => $qty,
                'attributes' => $attributes,
            ];
        }

        return $items;
    }

    public function item_is_ticket( $product ) {
        $is_ticket = $product->get_meta( '_is_ticket' );
        if ( ! $is_ticket && $product->get_parent_id() ) {
            $parent    = wc_get_product( $product->get_parent_id() );
            $is_ticket = $parent ? $parent->get_meta( '_is_ticket' ) : '';
        }
        return (bool) $is_ticket;
    }

    public function make_token( $order_id, $item_id, $index ) {
        $secret = defined( 'WCTQR_SECRET' ) ? WCTQR_SECRET : wp_salt( 'auth' );
        return hash_hmac( 'sha256', "{$order_id}:{$item_id}:{$index}", $secret );
    }
}

new WCTQR_Generator();
