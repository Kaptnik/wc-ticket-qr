<?php
/**
 * WCTQR_Settings
 * Configurable settings panel under WooCommerce → Ticket QR: Settings
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WCTQR_Settings {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            'Ticket QR: Settings',
            'Ticket QR: Settings',
            'manage_woocommerce',
            'wctqr-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() {
        $fields = self::get_fields();
        foreach ( $fields as $key => $field ) {
            register_setting( 'wctqr_settings', $key, [
                'sanitize_callback' => $field['sanitize'] ?? 'sanitize_text_field',
                'default'           => $field['default'],
            ]);
        }
    }

    /**
     * All configurable settings.
     */
    public static function get_fields() {
        return [
            // Email
            'wctqr_email_subject' => [
                'label'    => 'Email Subject',
                'type'     => 'text',
                'default'  => 'Your ticket(s) for order #{order_id}',
                'desc'     => 'Use {order_id} as a placeholder.',
                'section'  => 'email',
            ],
            'wctqr_email_heading' => [
                'label'    => 'Email Heading',
                'type'     => 'text',
                'default'  => 'Here are your tickets!',
                'section'  => 'email',
            ],
            'wctqr_email_intro' => [
                'label'    => 'Email Intro Text',
                'type'     => 'textarea',
                'default'  => 'Your ticket(s) for order #{order_id} are below. Please present the QR code at the venue entrance.',
                'desc'     => 'Use {first_name} and {order_id} as placeholders.',
                'section'  => 'email',
            ],
            'wctqr_email_footer' => [
                'label'    => 'Ticket Footer Note',
                'type'     => 'text',
                'default'  => 'Single-use &bull; Do not share &bull; Screenshot or print this email',
                'section'  => 'email',
            ],
            'wctqr_email_ids' => [
                'label'    => 'Send tickets on these WC emails',
                'type'     => 'checkboxes',
                'options'  => [
                    'customer_processing_order' => 'Order Processing',
                    'customer_completed_order'  => 'Order Completed',
                ],
                'default'  => ['customer_processing_order', 'customer_completed_order'],
                'sanitize' => [ __CLASS__, 'sanitize_array' ],
                'section'  => 'email',
            ],
            // Ticket appearance
            'wctqr_ticket_title' => [
                'label'    => 'Ticket Card Title',
                'type'     => 'text',
                'default'  => 'Admit One',
                'section'  => 'ticket',
            ],
            'wctqr_qr_module_size' => [
                'label'    => 'QR Code Size (px)',
                'type'     => 'number',
                'default'  => '300',
                'desc'     => 'Size of the QR code image in the email (pixels).',
                'section'  => 'ticket',
            ],
            // Scanner
            'wctqr_max_log' => [
                'label'    => 'Scanner Log — Max Entries',
                'type'     => 'number',
                'default'  => '10',
                'desc'     => 'How many recent scans to keep in the session log.',
                'section'  => 'scanner',
            ],
            // Bulk
            'wctqr_batch_size' => [
                'label'    => 'Bulk Action Batch Size',
                'type'     => 'number',
                'default'  => '10',
                'desc'     => 'Number of orders processed per batch. Keep at 10 to avoid timeouts.',
                'section'  => 'bulk',
            ],
            // Refund
            'wctqr_void_on_cancel' => [
                'label'    => 'Void tickets on order cancellation',
                'type'     => 'checkbox',
                'default'  => '1',
                'section'  => 'refund',
            ],
            'wctqr_send_void_email' => [
                'label'    => 'Send cancellation email to customer when ticket is voided',
                'type'     => 'checkbox',
                'default'  => '1',
                'section'  => 'refund',
            ],
            'wctqr_void_email_subject' => [
                'label'    => 'Void Email Subject',
                'type'     => 'text',
                'default'  => 'Your ticket for order #{order_id} has been cancelled',
                'section'  => 'refund',
            ],
            // Security
            'wctqr_secret' => [
                'label'    => 'Token Secret Key',
                'type'     => 'password',
                'default'  => '',
                'desc'     => 'Leave blank to use WordPress auth salt. For best security, set a long random string here (or in wp-config.php as WCTQR_SECRET).',
                'section'  => 'security',
            ],
        ];
    }

    public static function get( $key ) {
        $fields  = self::get_fields();
        $default = $fields[$key]['default'] ?? '';
        // wp-config constant takes priority
        $const = strtoupper( $key );
        if ( defined( $const ) ) return constant( $const );
        return get_option( $key, $default );
    }

    public static function sanitize_array( $value ) {
        if ( ! is_array( $value ) ) return [];
        return array_map( 'sanitize_key', $value );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;

        if ( isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'],'wctqr_settings') ) {
            foreach ( self::get_fields() as $key => $field ) {
                if ( $field['type'] === 'checkboxes' ) {
                    $val = isset($_POST[$key]) ? array_map('sanitize_key', (array)$_POST[$key]) : [];
                    update_option( $key, $val );
                } elseif ( $field['type'] === 'checkbox' ) {
                    update_option( $key, isset($_POST[$key]) ? '1' : '0' );
                } else {
                    $san = $field['sanitize'] ?? 'sanitize_text_field';
                    if ( is_string($san) ) {
                        update_option( $key, call_user_func($san, $_POST[$key] ?? '') );
                    }
                }
            }
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        $sections = [
            'email'    => 'Email Settings',
            'ticket'   => 'Ticket Appearance',
            'scanner'  => 'Scanner Settings',
            'bulk'     => 'Bulk Actions',
            'refund'   => 'Refunds & Cancellations',
            'security' => 'Security',
        ];

        echo '<div class="wrap"><h1>&#127903; Ticket QR &mdash; Settings</h1>';
        echo '<form method="post">';
        wp_nonce_field('wctqr_settings');

        foreach ( $sections as $sec_key => $sec_label ) {
            $sec_fields = array_filter( self::get_fields(), function($f) use ($sec_key) {
                return ($f['section'] ?? '') === $sec_key;
            });
            if ( empty($sec_fields) ) continue;

            echo '<h2 style="margin-top:28px;">' . esc_html($sec_label) . '</h2>';
            echo '<table class="form-table">';

            foreach ( $sec_fields as $key => $field ) {
                $value = self::get( $key );
                echo '<tr><th><label for="' . esc_attr($key) . '">' . esc_html($field['label']) . '</label></th><td>';

                switch ( $field['type'] ) {
                    case 'text':
                    case 'password':
                    case 'number':
                        $type = $field['type'] === 'password' ? 'password' : ($field['type'] === 'number' ? 'number' : 'text');
                        echo '<input type="' . $type . '" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" class="regular-text" />';
                        break;
                    case 'textarea':
                        echo '<textarea id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" rows="3" class="large-text">' . esc_textarea($value) . '</textarea>';
                        break;
                    case 'checkbox':
                        echo '<input type="checkbox" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="1" ' . checked('1', $value, false) . ' />';
                        break;
                    case 'checkboxes':
                        $checked = is_array($value) ? $value : (array)$value;
                        foreach ( $field['options'] as $opt_key => $opt_label ) {
                            $is_checked = in_array($opt_key, $checked);
                            echo '<label style="display:block;margin-bottom:4px;">';
                            echo '<input type="checkbox" name="' . esc_attr($key) . '[]" value="' . esc_attr($opt_key) . '" ' . ($is_checked?'checked':'') . ' /> ';
                            echo esc_html($opt_label) . '</label>';
                        }
                        break;
                }

                if ( isset($field['desc']) ) {
                    echo '<p class="description">' . wp_kses_post($field['desc']) . '</p>';
                }
                echo '</td></tr>';
            }
            echo '</table>';
        }

        echo '<p class="submit"><input type="submit" class="button-primary" value="Save Settings" /></p>';
        echo '</form></div>';
    }
}

new WCTQR_Settings();
