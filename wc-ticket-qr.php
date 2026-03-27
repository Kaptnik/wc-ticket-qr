<?php
/**
 * Plugin Name:       WooCommerce Ticket QR
 * Plugin URI:        https://github.com/YOUR_USERNAME/wc-ticket-qr
 * Description:       Generates unique, single-use QR code tickets for WooCommerce. Supports PDF tickets, bulk sending, door scanning, and refund invalidation.
 * Version:           1.1.0
 * Author:            Karthik Umashankar
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wc-ticket-qr
 * Requires Plugins:  woocommerce
 * Requires at least: 6.0
 * Tested up to:      6.5
 * WC requires at least: 7.0
 * WC tested up to:   8.9
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WCTQR_VERSION', '1.1.0' );
define( 'WCTQR_PATH',    plugin_dir_path( __FILE__ ) );
define( 'WCTQR_URL',     plugin_dir_url( __FILE__ ) );

register_activation_hook( __FILE__, 'wctqr_activate' );
function wctqr_activate() {
    global $wpdb;
    $table   = $wpdb->prefix . 'ticket_qr';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id      BIGINT UNSIGNED NOT NULL,
        order_item_id BIGINT UNSIGNED NOT NULL,
        token         VARCHAR(64)     NOT NULL,
        scanned       TINYINT(1)      NOT NULL DEFAULT 0,
        scanned_at    DATETIME                 DEFAULT NULL,
        voided        TINYINT(1)      NOT NULL DEFAULT 0,
        voided_at     DATETIME                 DEFAULT NULL,
        created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY   token (token),
        KEY          order_id (order_id)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    update_option( 'wctqr_db_version', WCTQR_VERSION );

    $upload    = wp_upload_dir();
    $cache_dir = trailingslashit( $upload['basedir'] ) . 'wctqr-tickets/';
    if ( ! is_dir($cache_dir) ) wp_mkdir_p($cache_dir);
    if ( ! file_exists($cache_dir.'.htaccess') ) {
        file_put_contents($cache_dir.'.htaccess', "Options -Indexes\n<Files *.php>\n  Require all denied\n</Files>\n");
    }
    if ( ! file_exists($cache_dir.'index.php') ) {
        file_put_contents($cache_dir.'index.php', "<?php // Silence is golden.\n");
    }

    require_once WCTQR_PATH . 'includes/class-ticket-scanner.php';
    WCTQR_Scanner::maybe_create_page();
}

register_deactivation_hook( __FILE__, 'wctqr_deactivate' );
function wctqr_deactivate() {}

add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
} );

add_action( 'plugins_loaded', 'wctqr_init' );
function wctqr_init() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action('admin_notices', function(){
            echo '<div class="notice notice-error"><p><strong>WooCommerce Ticket QR</strong> requires WooCommerce to be active.</p></div>';
        });
        return;
    }
    require_once WCTQR_PATH . 'includes/class-ticket-settings.php';
    require_once WCTQR_PATH . 'includes/class-ticket-scanner.php';
    require_once WCTQR_PATH . 'includes/class-ticket-generator.php';
    require_once WCTQR_PATH . 'includes/class-ticket-validator.php';
    require_once WCTQR_PATH . 'includes/class-ticket-pdf.php';
    require_once WCTQR_PATH . 'includes/class-ticket-email.php';
    require_once WCTQR_PATH . 'includes/class-ticket-retro.php';
    require_once WCTQR_PATH . 'includes/class-ticket-refund.php';
    require_once WCTQR_PATH . 'includes/class-ticket-bulk.php';
    require_once WCTQR_PATH . 'admin/admin-page.php';
}
