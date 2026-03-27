<?php
/**
 * WCTQR_Refund
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WCTQR_Refund {

    public function __construct() {
        add_action( 'woocommerce_order_refunded',         [ $this, 'handle_full_refund' ],    10, 2 );
        add_action( 'woocommerce_refund_created',         [ $this, 'handle_partial_refund' ], 10, 2 );
        add_action( 'woocommerce_order_status_refunded',  [ $this, 'invalidate_all' ] );
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'invalidate_all' ] );
    }

    public function handle_full_refund( $order_id, $refund_id ) {
        $this->invalidate_all( $order_id );
    }

    public function handle_partial_refund( $refund_id, $args ) {
        global $wpdb;

        $order_id = absint( isset( $args['order_id'] ) ? $args['order_id'] : 0 );
        if ( ! $order_id ) return;

        $order  = wc_get_order( $order_id );
        $refund = wc_get_order( $refund_id );
        if ( ! $order || ! $refund ) return;

        foreach ( $refund->get_items() as $refund_item ) {
            $original_item_id = absint( $refund_item->get_meta( '_refunded_item_id' ) );
            if ( ! $original_item_id ) continue;

            $qty_refunded = abs( $refund_item->get_quantity() );
            if ( $qty_refunded < 1 ) continue;

            $tickets = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ticket_qr
                 WHERE order_id = %d AND order_item_id = %d AND voided = 0
                 ORDER BY id ASC LIMIT %d",
                $order_id, $original_item_id, $qty_refunded
            ) );

            foreach ( $tickets as $ticket ) {
                $this->void_ticket( $ticket, $order );
            }
        }
    }

    public function invalidate_all( $order_id ) {
        global $wpdb;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $tickets = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ticket_qr WHERE order_id = %d AND voided = 0",
            $order_id
        ) );

        foreach ( $tickets as $ticket ) {
            $this->void_ticket( $ticket, $order );
        }
    }

    private function void_ticket( $ticket, $order ) {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'ticket_qr',
            [ 'voided' => 1, 'voided_at' => current_time( 'mysql' ) ],
            [ 'id' => $ticket->id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );

        WCTQR_PDF_Generator::delete( $ticket->token );
        $this->send_void_email( $order, $ticket );
    }

    private function send_void_email( $order, $ticket ) {
        $mailer      = WC()->mailer();
        $token_short = strtoupper( substr( $ticket->token, 0, 8 ) );
        $subject     = sprintf( 'Your ticket for order #%d has been cancelled', $order->get_id() );

        $body = '<p>Hi ' . esc_html( $order->get_billing_first_name() ) . ',</p>'
              . '<p>Your ticket (ref: ' . esc_html( $token_short ) . '&hellip;) from order #' . intval( $order->get_id() ) . ' has been cancelled due to a refund or order cancellation.</p>'
              . '<p>If you believe this is an error, please contact us.</p>';

        $message = $mailer->wrap_message( 'Ticket Cancelled', $body );
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        wp_mail( $order->get_billing_email(), $subject, $message, $headers );
    }
}

new WCTQR_Refund();
