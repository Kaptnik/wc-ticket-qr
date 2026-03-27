<?php
/**
 * WCTQR Admin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        'Ticket QR Codes',
        'Ticket QR',
        'manage_woocommerce',
        'wctqr-tickets',
        'wctqr_admin_page'
    );
} );

function wctqr_admin_page() {
    global $wpdb;

    $per_page    = 50;
    $current     = max( 1, absint( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );
    $offset      = ( $current - 1 ) * $per_page;
    $total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ticket_qr" );
    $total_pages = (int) ceil( $total / $per_page );

    $filter = sanitize_key( isset( $_GET['filter'] ) ? $_GET['filter'] : 'all' );
    if ( $filter === 'unused' ) {
        $where = 'WHERE scanned = 0 AND voided = 0';
    } elseif ( $filter === 'scanned' ) {
        $where = 'WHERE scanned = 1';
    } elseif ( $filter === 'voided' ) {
        $where = 'WHERE voided = 1';
    } else {
        $where = '';
    }

    $tickets = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ticket_qr {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
        $per_page, $offset
    ) );

    $base_url      = admin_url( 'admin.php?page=wctqr-tickets' );
    $total_all     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ticket_qr" );
    $total_unused  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ticket_qr WHERE scanned=0 AND voided=0" );
    $total_scanned = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ticket_qr WHERE scanned=1" );
    $total_voided  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ticket_qr WHERE voided=1" );

    echo '<div class="wrap">';
    echo '<h1 class="wp-heading-inline">Ticket QR Codes</h1><hr class="wp-header-end">';

    $filters = [
        'all'     => [ 'All',     $total_all ],
        'unused'  => [ 'Unused',  $total_unused ],
        'scanned' => [ 'Scanned', $total_scanned ],
        'voided'  => [ 'Voided',  $total_voided ],
    ];
    echo '<ul class="subsubsub">';
    $links = [];
    foreach ( $filters as $key => $data ) {
        $label  = $data[0];
        $count  = $data[1];
        $class  = ( $key === $filter ) ? ' class="current"' : '';
        $url    = esc_url( add_query_arg( 'filter', $key, $base_url ) );
        $links[] = "<li><a href='{$url}'{$class}>{$label} <span class='count'>({$count})</span></a></li>";
    }
    echo implode( ' | ', $links );
    echo '</ul>';

    echo '<table class="wp-list-table widefat fixed striped" style="margin-top:12px;">';
    echo '<thead><tr>
        <th style="width:50px;">ID</th>
        <th>Order</th>
        <th>Token (partial)</th>
        <th>Status</th>
        <th>Scanned At</th>
        <th>Created At</th>
    </tr></thead><tbody>';

    if ( empty( $tickets ) ) {
        echo '<tr><td colspan="6">No tickets found.</td></tr>';
    }

    foreach ( $tickets as $t ) {
        $order_url = admin_url( 'post.php?post=' . $t->order_id . '&action=edit' );

        if ( $t->voided ) {
            $status = '<span style="color:#c00;font-weight:600;">&#10060; Voided</span>';
        } elseif ( $t->scanned ) {
            $status = '<span style="color:#d97706;font-weight:600;">&#9989; Scanned</span>';
        } else {
            $status = '<span style="color:#16a34a;font-weight:600;">&#9711; Unused</span>';
        }

        echo '<tr>
            <td>' . intval( $t->id ) . '</td>
            <td><a href="' . esc_url( $order_url ) . '">#' . intval( $t->order_id ) . '</a></td>
            <td style="font-family:monospace;">' . esc_html( strtoupper( substr( $t->token, 0, 12 ) ) ) . '&hellip;</td>
            <td>' . $status . '</td>
            <td>' . esc_html( $t->scanned_at ? $t->scanned_at : '&mdash;' ) . '</td>
            <td>' . esc_html( $t->created_at ) . '</td>
        </tr>';
    }

    echo '</tbody></table>';

    if ( $total_pages > 1 ) {
        echo '<div class="tablenav bottom"><div class="tablenav-pages">';
        echo paginate_links( [
            'base'      => add_query_arg( 'paged', '%#%', $base_url ),
            'format'    => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'total'     => $total_pages,
            'current'   => $current,
        ] );
        echo '</div></div>';
    }

    echo '</div>';
}

// Product fields
add_action( 'woocommerce_product_options_general_product_data', function () {
    echo '<div class="options_group">';
    woocommerce_wp_checkbox( [
        'id'          => '_is_ticket',
        'label'       => 'This product is a ticket',
        'description' => 'When checked, a unique QR code PDF will be generated for each unit purchased.',
    ] );
    woocommerce_wp_text_input( [
        'id'          => '_event_date',
        'label'       => 'Event Date',
        'placeholder' => 'e.g. Saturday 14 June 2025, 7:00 PM',
        'desc_tip'    => true,
        'description' => 'Shown on the printed ticket PDF.',
    ] );
    woocommerce_wp_text_input( [
        'id'          => '_event_venue',
        'label'       => 'Event Venue',
        'placeholder' => 'e.g. The Grand Hall, 123 Main St',
        'desc_tip'    => true,
        'description' => 'Shown on the printed ticket PDF.',
    ] );
    echo '</div>';
} );

add_action( 'woocommerce_process_product_meta', function ( $post_id ) {
    update_post_meta( $post_id, '_is_ticket',   isset( $_POST['_is_ticket'] ) ? 'yes' : '' );
    update_post_meta( $post_id, '_event_date',  sanitize_text_field( isset( $_POST['_event_date'] )  ? $_POST['_event_date']  : '' ) );
    update_post_meta( $post_id, '_event_venue', sanitize_text_field( isset( $_POST['_event_venue'] ) ? $_POST['_event_venue'] : '' ) );
} );

// ---------------------------------------------------------------------------
// Debug / System Status page
// ---------------------------------------------------------------------------
add_action( 'admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        'Ticket QR: System Status',
        'Ticket QR: Status',
        'manage_woocommerce',
        'wctqr-status',
        'wctqr_status_page'
    );
}, 99 );

function wctqr_status_page() {
    $fpdf_path    = WP_PLUGIN_DIR . '/wc-ticket-qr/lib/fpdf/fpdf.php';
    $fpdf_exists  = file_exists( $fpdf_path );
    $upload       = wp_upload_dir();
    $cache_dir    = trailingslashit( $upload['basedir'] ) . 'wctqr-tickets/';
    $cache_exists = is_dir( $cache_dir );
    $cache_write  = $cache_exists && is_writable( $cache_dir );
    $php_version  = phpversion();
    $wc_version   = defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown';

    // Test QR fetch
    $qr_test = 'Not tested';
    if ( $fpdf_exists ) {
        $test_url = 'https://chart.googleapis.com/chart?chs=100x100&cht=qr&chl=test';
        $resp     = wp_remote_get( $test_url, array( 'timeout' => 10 ) );
        $qr_test  = is_wp_error( $resp )
            ? 'FAIL: ' . $resp->get_error_message()
            : 'OK (HTTP ' . wp_remote_retrieve_response_code( $resp ) . ')';
    }

    // Test FPDF load
    $fpdf_load = 'Not tested';
    if ( $fpdf_exists ) {
        if ( ! class_exists( 'FPDF' ) ) {
            @include_once $fpdf_path;
        }
        $fpdf_load = class_exists( 'FPDF' ) ? 'OK — class loaded' : 'FAIL — class not found after include';
    }

    // Check debug log
    $debug_log     = WP_CONTENT_DIR . '/debug.log';
    $debug_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
    $log_tail      = '';
    if ( $debug_enabled && file_exists( $debug_log ) ) {
        $lines    = file( $debug_log );
        $wctqr    = array_filter( $lines, function( $l ) { return strpos( $l, 'WCTQR' ) !== false; } );
        $log_tail = implode( '', array_slice( array_values( $wctqr ), -20 ) );
    }

    function wctqr_status_row( $label, $ok, $value ) {
        $icon = $ok ? '<span style="color:#16a34a;">&#10003;</span>' : '<span style="color:#c00;">&#10007;</span>';
        echo '<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;font-weight:600;">' . esc_html( $label ) . '</td>'
           . '<td style="padding:8px 12px;border-bottom:1px solid #eee;">' . $icon . ' ' . esc_html( $value ) . '</td></tr>';
    }

    echo '<div class="wrap"><h1>Ticket QR &mdash; System Status</h1>';
    echo '<table style="background:#fff;border:1px solid #ddd;border-collapse:collapse;min-width:500px;">';
    wctqr_status_row( 'PHP Version',       version_compare( $php_version, '7.4', '>=' ),  $php_version );
    wctqr_status_row( 'GD Library',        function_exists('imagecreatetruecolor'),        function_exists('imagecreatetruecolor') ? 'Available' : 'NOT available' );
    wctqr_status_row( 'Imagick',           class_exists('Imagick'),                        class_exists('Imagick') ? 'Available' : 'NOT available' );
    wctqr_status_row( 'WooCommerce',       $wc_version !== 'unknown',                      $wc_version );
    wctqr_status_row( 'FPDF file exists',  $fpdf_exists,   $fpdf_exists  ? $fpdf_path : 'NOT FOUND at ' . $fpdf_path );
    wctqr_status_row( 'FPDF loads OK',     $fpdf_load === 'OK — class loaded', $fpdf_load );
    wctqr_status_row( 'Cache dir exists',  $cache_exists,  $cache_dir );
    wctqr_status_row( 'Cache dir writable',$cache_write,   $cache_write ? 'Yes' : 'No — check folder permissions' );
    wctqr_status_row( 'QR API reachable',  strpos( $qr_test, 'OK' ) === 0, $qr_test );
    wctqr_status_row( 'WP_DEBUG_LOG',      $debug_enabled, $debug_enabled ? 'Enabled' : 'Disabled (add to wp-config.php to see errors)' );

    // Scanner page
    $scanner_id  = get_option('wctqr_scanner_page_id');
    $scanner_url = $scanner_id ? get_permalink($scanner_id) : false;
    echo '<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;font-weight:600;">Scanner Page</td>'
       . '<td style="padding:8px 12px;border-bottom:1px solid #eee;">';
    if ( $scanner_url ) {
        echo '<a href="' . esc_url($scanner_url) . '" target="_blank">' . esc_html($scanner_url) . '</a>';
    } else {
        echo '<span style="color:#c00;">Not created — deactivate and reactivate the plugin to create it.</span>';
    }
    echo '</td></tr>';

    // QR generation test
    require_once WP_PLUGIN_DIR . '/wc-ticket-qr/lib/phpqrcode/phpqrcode.php';
    $test_png = WCTQR_QRCode::png( 'https://seattlekannadasangha.com/test', 6, 4 );
    $qr_ok    = $test_png && strlen( $test_png ) > 100;
    $qr_b64   = $qr_ok ? base64_encode( $test_png ) : null;
    wctqr_status_row( 'QR PNG generation', $qr_ok, $qr_ok ? 'OK (' . strlen($test_png) . ' bytes)' : 'FAILED — check error log' );

    echo '</table>';

    // Show the test QR if it worked
    if ( $qr_b64 ) {
        echo '<h2>Test QR Code</h2>';
        echo '<p style="color:#666;font-size:13px;">If you can see a QR code below, generation is working correctly and the issue is in the email delivery of the image.</p>';
        echo '<img src="data:image/png;base64,' . $qr_b64 . '" style="border:1px solid #ddd;padding:8px;background:#fff;" />';
    }

    if ( $log_tail ) {
        echo '<h2>Recent WCTQR entries in debug.log</h2>';
        echo '<pre style="background:#1e1e2e;color:#cdd6f4;padding:16px;overflow:auto;max-height:400px;">'
           . esc_html( $log_tail ) . '</pre>';
    } else {
        echo '<p style="margin-top:16px;color:#666;">No WCTQR entries in debug.log yet. '
           . 'Add <code>define(\'WP_DEBUG\',true); define(\'WP_DEBUG_LOG\',true);</code> to wp-config.php, '
           . 'trigger the error again, then reload this page.</p>';
    }

    echo '</div>';
}
