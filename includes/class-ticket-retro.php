<?php
/**
 * WCTQR_Retro
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WCTQR_Retro {

    public function __construct() {
        add_filter( 'woocommerce_order_actions',             [ $this, 'add_order_action' ] );
        add_action( 'woocommerce_order_action_wctqr_resend', [ $this, 'process_dropdown_action' ] );
        add_action( 'add_meta_boxes',                        [ $this, 'add_meta_box' ] );
        add_action( 'admin_post_wctqr_generate_send',        [ $this, 'handle_manual_send' ] );
        add_action( 'admin_notices',                         [ $this, 'show_notice' ] );
    }

    public function add_order_action( $actions ) {
        $actions['wctqr_resend'] = 'Generate & Resend QR Ticket(s)';
        return $actions;
    }

    public function process_dropdown_action( $order ) {
        $this->generate_and_send( $order->get_id() );
    }

    public function add_meta_box() {
        $screens = [ 'shop_order', 'woocommerce_page_wc-orders' ];
        foreach ( $screens as $screen ) {
            add_meta_box(
                'wctqr_meta_box',
                'Ticket QR Codes',
                [ $this, 'render_meta_box' ],
                $screen,
                'side',
                'default'
            );
        }
    }

    public function render_meta_box( $post_or_order ) {
        global $wpdb;

        if ( is_a( $post_or_order, 'WC_Order' ) ) {
            $order_id = $post_or_order->get_id();
        } elseif ( isset( $post_or_order->ID ) ) {
            $order_id = $post_or_order->ID;
        } else {
            $order_id = absint( $post_or_order );
        }

        $tickets = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ticket_qr WHERE order_id = %d ORDER BY id ASC",
            $order_id
        ) );

        echo '<div style="font-size:13px;">';

        if ( empty( $tickets ) ) {
            echo '<p style="color:#888;">No tickets generated for this order yet.</p>';
        } else {
            echo '<p><strong>' . count( $tickets ) . ' ticket(s) on this order:</strong></p>';
            echo '<table style="width:100%;border-collapse:collapse;">';
            echo '<tr style="border-bottom:1px solid #ddd;"><th style="text-align:left;padding:4px 2px;">Ref</th><th style="text-align:left;padding:4px 2px;">Status</th></tr>';

            foreach ( $tickets as $t ) {
                $ref = esc_html( strtoupper( substr( $t->token, 0, 8 ) ) );
                if ( $t->voided ) {
                    $status = '<span style="color:#c00;">&#10060; Voided</span>';
                } elseif ( $t->scanned ) {
                    $status = '<span style="color:#d97706;">&#9989; Scanned</span>';
                } else {
                    $status = '<span style="color:#16a34a;">&#9711; Unused</span>';
                }
                echo "<tr style='border-bottom:1px solid #eee;'><td style='padding:4px 2px;font-family:monospace;'>{$ref}&hellip;</td><td style='padding:4px 2px;'>{$status}</td></tr>";
            }
            echo '</table>';
        }

        $nonce = wp_create_nonce( 'wctqr_send_' . $order_id );
        $url   = admin_url( "admin-post.php?action=wctqr_generate_send&order_id={$order_id}&_wpnonce={$nonce}" );
        echo '<a href="' . esc_url( $url ) . '" class="button button-primary" style="margin-top:12px;width:100%;text-align:center;box-sizing:border-box;">Generate &amp; Resend Ticket Email</a>';
        echo '</div>';
    }

    public function handle_manual_send() {
        $order_id = absint( isset( $_GET['order_id'] ) ? $_GET['order_id'] : 0 );

        if ( ! $order_id || ! check_admin_referer( 'wctqr_send_' . $order_id ) ) {
            wp_die( 'Security check failed.' );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized.' );
        }

        // Catch any fatal errors during send
        try {
            $result = $this->generate_and_send( $order_id );
            $status = $result ? 'sent' : 'error';
        } catch ( Exception $e ) {
            error_log( 'WCTQR handle_manual_send Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
            $status = 'error';
        } catch ( Error $e ) {
            error_log( 'WCTQR handle_manual_send Fatal Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
            $status = 'error';
        }

        wp_safe_redirect( admin_url( "post.php?post={$order_id}&action=edit&wctqr={$status}" ) );
        exit;
    }

    public function show_notice() {
        $status = sanitize_key( isset( $_GET['wctqr'] ) ? $_GET['wctqr'] : '' );
        if ( ! $status ) return;

        if ( $status === 'sent' ) {
            echo '<div class="notice notice-success is-dismissible"><p>Ticket QR: Email generated and sent successfully.</p></div>';
        } elseif ( $status === 'error' ) {
            echo '<div class="notice notice-error is-dismissible"><p>Ticket QR: Could not send email. '
               . 'Please enable WP_DEBUG_LOG in wp-config.php, try again, then check WooCommerce &rarr; Ticket QR: Status for the error details.</p></div>';
        }
    }

    public function generate_and_send( $order_id ) {
        error_log( 'WCTQR: generate_and_send called for order ' . $order_id );

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            error_log( 'WCTQR: could not load order ' . $order_id );
            return false;
        }

        // Delegate to generator — it handles both modes correctly
        $generator = new WCTQR_Generator();
        $generator->generate_tickets( $order_id );

        error_log( 'WCTQR: tokens generated, now sending email' );

        $email  = new WCTQR_Ticket_Email();
        $result = $email->trigger( $order_id );

        error_log( 'WCTQR: email trigger returned ' . var_export( $result, true ) );

        return $result;
    }
}

new WCTQR_Retro();
