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
        foreach ( self::get_fields() as $key => $field ) {
            register_setting( 'wctqr_settings', $key, [
                'sanitize_callback' => $field['sanitize'] ?? 'sanitize_text_field',
                'default'           => $field['default'],
            ]);
        }
    }

    public static function get_fields() {
        return [
            // ── Ticket mode ───────────────────────────────────────────────────
            'wctqr_qr_mode' => [
                'label'   => 'QR Code Mode',
                'type'    => 'radio',
                'options' => [
                    'per_ticket' => [
                        'label' => 'One QR code per ticket',
                        'desc'  => 'Each unit purchased gets its own unique QR code. Each scan admits <strong>1 person</strong>. Best for individually named tickets or assigned seating.',
                    ],
                    'per_order' => [
                        'label' => 'One QR code for the entire order',
                        'desc'  => 'The whole order gets a single QR code. One scan admits <strong>all attendees in that order</strong>. Best for group bookings or family tickets.',
                    ],
                ],
                'default' => 'per_ticket',
                'section' => 'ticket',
            ],
            // ── Email ─────────────────────────────────────────────────────────
            'wctqr_email_subject' => [
                'label'   => 'Email Subject',
                'type'    => 'text',
                'default' => 'Your ticket(s) for order #{order_id}',
                'desc'    => 'Placeholders: <code>{order_id}</code>',
                'section' => 'email',
            ],
            'wctqr_email_heading' => [
                'label'   => 'Email Heading',
                'type'    => 'text',
                'default' => 'Here are your tickets!',
                'section' => 'email',
            ],
            'wctqr_email_intro' => [
                'label'   => 'Email Intro Text',
                'type'    => 'textarea',
                'default' => 'Your ticket(s) for order #{order_id} are below. Please present the QR code at the venue entrance.',
                'desc'    => 'Placeholders: <code>{first_name}</code>, <code>{order_id}</code>',
                'section' => 'email',
            ],
            'wctqr_email_footer' => [
                'label'   => 'Ticket Footer Note',
                'type'    => 'text',
                'default' => 'Single-use &bull; Do not share &bull; Screenshot or print this email',
                'section' => 'email',
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
            // ── Ticket appearance ─────────────────────────────────────────────
            'wctqr_ticket_title' => [
                'label'   => 'Ticket Card Title',
                'type'    => 'text',
                'default' => 'Admit One',
                'desc'    => 'Placeholders: <code>{ticket_number}</code>, <code>{total_tickets}</code>, <code>{number_of_items_in_order}</code>, <code>{admits}</code> (number this ticket admits).<br>Example: <code>Admit {admits}</code> shows "Admit 3" for a 3-ticket order in per-order mode, "Admit 1" in per-ticket mode.',
                'section' => 'ticket',
            ],
            'wctqr_qr_module_size' => [
                'label'   => 'QR Code Size (px)',
                'type'    => 'number',
                'default' => '300',
                'desc'    => 'Width and height of the QR code image in the email.',
                'section' => 'ticket',
            ],
            // ── Scanner ───────────────────────────────────────────────────────
            'wctqr_max_log' => [
                'label'   => 'Scanner Log — Max Entries',
                'type'    => 'number',
                'default' => '10',
                'desc'    => 'How many recent scans to keep in the session log.',
                'section' => 'scanner',
            ],
            // ── Bulk ──────────────────────────────────────────────────────────
            'wctqr_batch_size' => [
                'label'   => 'Bulk Action Batch Size',
                'type'    => 'number',
                'default' => '10',
                'desc'    => 'Orders processed per batch. Keep at 10 to avoid timeouts.',
                'section' => 'bulk',
            ],
            // ── Refund ────────────────────────────────────────────────────────
            'wctqr_void_on_cancel' => [
                'label'   => 'Void tickets on order cancellation',
                'type'    => 'checkbox',
                'default' => '1',
                'section' => 'refund',
            ],
            'wctqr_send_void_email' => [
                'label'   => 'Send cancellation email when ticket is voided',
                'type'    => 'checkbox',
                'default' => '1',
                'section' => 'refund',
            ],
            'wctqr_void_email_subject' => [
                'label'   => 'Void Email Subject',
                'type'    => 'text',
                'default' => 'Your ticket for order #{order_id} has been cancelled',
                'section' => 'refund',
            ],
            // ── Security ──────────────────────────────────────────────────────
            'wctqr_secret' => [
                'label'   => 'Token Secret Key',
                'type'    => 'password',
                'default' => '',
                'desc'    => 'Leave blank to use WordPress auth salt. For best security set a long random string here or in wp-config.php as <code>WCTQR_SECRET</code>.',
                'section' => 'security',
            ],
        ];
    }

    public static function get( $key ) {
        $fields  = self::get_fields();
        $default = $fields[$key]['default'] ?? '';
        $const   = strtoupper( $key );
        if ( defined( $const ) ) return constant( $const );
        return get_option( $key, $default );
    }

    public static function sanitize_array( $value ) {
        if ( ! is_array($value) ) return [];
        return array_map( 'sanitize_key', $value );
    }

    /** Convenience: is per-order mode active? */
    public static function is_per_order_mode() {
        return self::get('wctqr_qr_mode') === 'per_order';
    }

    public function render_settings_page() {
        if ( ! current_user_can('manage_woocommerce') ) return;

        if ( isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'],'wctqr_settings') ) {
            foreach ( self::get_fields() as $key => $field ) {
                if ( $field['type'] === 'checkboxes' ) {
                    $val = isset($_POST[$key]) ? array_map('sanitize_key',(array)$_POST[$key]) : [];
                    update_option( $key, $val );
                } elseif ( $field['type'] === 'checkbox' ) {
                    update_option( $key, isset($_POST[$key]) ? '1' : '0' );
                } elseif ( $field['type'] === 'radio' ) {
                    $allowed = array_keys( $field['options'] );
                    $val     = sanitize_key( $_POST[$key] ?? '' );
                    update_option( $key, in_array($val,$allowed) ? $val : $field['default'] );
                } else {
                    $san = $field['sanitize'] ?? 'sanitize_text_field';
                    if ( is_string($san) ) update_option( $key, call_user_func($san, $_POST[$key] ?? '') );
                }
            }
            echo '<div class="notice notice-success"><p>&#10003; Settings saved.</p></div>';
        }

        $sections = [
            'ticket'   => 'Ticket Mode &amp; Appearance',
            'email'    => 'Email Settings',
            'scanner'  => 'Scanner Settings',
            'bulk'     => 'Bulk Actions',
            'refund'   => 'Refunds &amp; Cancellations',
            'security' => 'Security',
        ];

        echo '<div class="wrap"><h1>&#127903; Ticket QR &mdash; Settings</h1>';
        echo '<form method="post">';
        wp_nonce_field('wctqr_settings');

        foreach ( $sections as $sec_key => $sec_label ) {
            $sec_fields = array_filter(self::get_fields(), function($f) use($sec_key){ return ($f['section']??'')===$sec_key; });
            if ( empty($sec_fields) ) continue;

            echo '<h2 style="border-top:1px solid #eee;padding-top:20px;margin-top:28px;">' . $sec_label . '</h2>';
            echo '<table class="form-table">';

            foreach ( $sec_fields as $key => $field ) {
                $value = self::get($key);
                echo '<tr><th><label for="' . esc_attr($key) . '">' . esc_html($field['label']) . '</label></th><td>';

                switch ( $field['type'] ) {
                    case 'text':
                    case 'number':
                        echo '<input type="' . ($field['type']==='number'?'number':'text') . '" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" class="regular-text" />';
                        break;
                    case 'password':
                        echo '<input type="password" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" class="regular-text" autocomplete="new-password" />';
                        break;
                    case 'textarea':
                        echo '<textarea id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" rows="3" class="large-text">' . esc_textarea($value) . '</textarea>';
                        break;
                    case 'checkbox':
                        echo '<input type="checkbox" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="1" ' . checked('1',$value,false) . ' />';
                        break;
                    case 'checkboxes':
                        $checked = is_array($value) ? $value : (array)$value;
                        foreach ( $field['options'] as $ok => $ol ) {
                            echo '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="' . esc_attr($key) . '[]" value="' . esc_attr($ok) . '" ' . (in_array($ok,$checked)?'checked':'') . ' /> ' . esc_html($ol) . '</label>';
                        }
                        break;
                    case 'radio':
                        foreach ( $field['options'] as $ok => $opt ) {
                            $selected = ($value === $ok);
                            echo '<label style="display:flex;align-items:flex-start;gap:10px;margin-bottom:14px;padding:14px;border:2px solid ' . ($selected?'#7c3aed':'#ddd') . ';border-radius:8px;cursor:pointer;background:' . ($selected?'#f5f3ff':'#fff') . ';">';
                            echo '<input type="radio" name="' . esc_attr($key) . '" value="' . esc_attr($ok) . '" ' . checked($ok,$value,false) . ' style="margin-top:3px;accent-color:#7c3aed;" />';
                            echo '<span><strong>' . esc_html($opt['label']) . '</strong>';
                            if ( isset($opt['desc']) ) echo '<br><span style="color:#666;font-size:13px;">' . wp_kses_post($opt['desc']) . '</span>';
                            echo '</span></label>';
                        }
                        break;
                }

                if ( isset($field['desc']) && $field['type'] !== 'radio' ) {
                    echo '<p class="description" style="margin-top:6px;">' . wp_kses_post($field['desc']) . '</p>';
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
