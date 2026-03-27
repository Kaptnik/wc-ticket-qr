<?php
/**
 * WCTQR_Bulk
 * Adds a "Generate & Send Ticket QR" bulk action to the WooCommerce orders list.
 * Processes in batches to avoid timeouts.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WCTQR_Bulk {

    const BATCH_SIZE = 10;

    public function __construct() {
        // Add bulk action to orders list
        add_filter( 'bulk_actions-edit-shop_order',              [ $this, 'add_bulk_action' ] );
        add_filter( 'bulk_actions-woocommerce_page_wc-orders',   [ $this, 'add_bulk_action' ] );

        // Handle the bulk action
        add_filter( 'handle_bulk_actions-edit-shop_order',             [ $this, 'handle_bulk_action' ], 10, 3 );
        add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders',  [ $this, 'handle_bulk_action' ], 10, 3 );

        // Show admin notice with results
        add_action( 'admin_notices', [ $this, 'show_notice' ] );

        // AJAX handler for batched processing
        add_action( 'wp_ajax_wctqr_bulk_process', [ $this, 'ajax_bulk_process' ] );

        // Bulk action page
        add_action( 'admin_menu', [ $this, 'add_bulk_page' ] );
    }

    public function add_bulk_action( $actions ) {
        $actions['wctqr_send_tickets'] = '&#127903; Generate &amp; Send Ticket QR';
        return $actions;
    }

    public function handle_bulk_action( $redirect_url, $action, $order_ids ) {
        if ( $action !== 'wctqr_send_tickets' ) return $redirect_url;
        if ( ! current_user_can( 'manage_woocommerce' ) ) return $redirect_url;

        $order_ids  = array_map( 'absint', $order_ids );
        $batch_size = (int) get_option( 'wctqr_batch_size', self::BATCH_SIZE );

        if ( count( $order_ids ) > $batch_size ) {
            // Store in transient and redirect to processing page
            $job_id = 'wctqr_bulk_' . time();
            set_transient( $job_id, $order_ids, HOUR_IN_SECONDS );
            return admin_url( 'admin.php?page=wctqr-bulk&job=' . $job_id . '&total=' . count($order_ids) );
        }

        // Small batch — process immediately
        $sent = 0; $failed = 0; $skipped = 0;
        $retro = new WCTQR_Retro();
        foreach ( $order_ids as $order_id ) {
            $result = $retro->generate_and_send( $order_id );
            if ( $result === false ) $failed++;
            elseif ( $result === null ) $skipped++;
            else $sent++;
        }

        return add_query_arg([
            'wctqr_bulk_sent'    => $sent,
            'wctqr_bulk_failed'  => $failed,
            'wctqr_bulk_skipped' => $skipped,
        ], $redirect_url );
    }

    public function show_notice() {
        if ( ! isset( $_GET['wctqr_bulk_sent'] ) ) return;
        $sent    = absint( $_GET['wctqr_bulk_sent'] );
        $failed  = absint( $_GET['wctqr_bulk_failed'] );
        $skipped = absint( $_GET['wctqr_bulk_skipped'] );
        echo '<div class="notice notice-success is-dismissible"><p>';
        echo '&#127903; <strong>Ticket QR bulk action complete.</strong> ';
        echo 'Sent: ' . $sent . '. ';
        if ( $failed )  echo 'Failed: ' . $failed . '. ';
        if ( $skipped ) echo 'Skipped (no ticket products): ' . $skipped . '.';
        echo '</p></div>';
    }

    public function add_bulk_page() {
        add_submenu_page(
            null, // hidden from menu
            'Ticket QR Bulk Processing',
            'Ticket QR Bulk',
            'manage_woocommerce',
            'wctqr-bulk',
            [ $this, 'render_bulk_page' ]
        );
    }

    public function render_bulk_page() {
        $job_id    = sanitize_key( $_GET['job'] ?? '' );
        $total     = absint( $_GET['total'] ?? 0 );
        $batch_size = (int) get_option( 'wctqr_batch_size', self::BATCH_SIZE );
        $ajax_url  = admin_url( 'admin-ajax.php' );
        $nonce     = wp_create_nonce( 'wctqr_bulk' );

        echo '<div class="wrap" id="wctqr-bulk-wrap">';
        echo '<h1>&#127903; Sending Ticket Emails</h1>';
        echo '<p>Processing <strong>' . intval($total) . '</strong> orders in batches of ' . intval($batch_size) . '.</p>';

        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;max-width:500px;">';
        echo '<div id="wctqr-progress-bar" style="height:20px;background:#f0f0f0;border-radius:10px;overflow:hidden;margin-bottom:12px;">';
        echo '<div id="wctqr-progress-fill" style="height:100%;background:#7c3aed;width:0%;transition:width 0.3s;border-radius:10px;"></div></div>';
        echo '<p id="wctqr-progress-text" style="margin:0;color:#666;font-size:14px;">Starting&hellip;</p>';
        echo '<div id="wctqr-log-output" style="margin-top:16px;max-height:300px;overflow-y:auto;font-size:13px;font-family:monospace;background:#1e1e2e;color:#cdd6f4;padding:12px;border-radius:6px;display:none;"></div>';
        echo '</div>';

        echo '<div id="wctqr-bulk-done" style="display:none;margin-top:20px;">';
        echo '<a href="' . admin_url('edit.php?post_type=shop_order') . '" class="button button-primary">Back to Orders</a>';
        echo '</div>';
        echo '</div>';
        ?>
        <script>
        (function(){
            var jobId    = <?php echo json_encode($job_id); ?>;
            var total    = <?php echo intval($total); ?>;
            var batchSize= <?php echo intval($batch_size); ?>;
            var ajaxUrl  = <?php echo json_encode($ajax_url); ?>;
            var nonce    = <?php echo json_encode($nonce); ?>;
            var offset   = 0;
            var sent=0, failed=0, skipped=0;

            function updateProgress() {
                var pct = total > 0 ? Math.round((offset/total)*100) : 0;
                document.getElementById('wctqr-progress-fill').style.width = pct+'%';
                document.getElementById('wctqr-progress-text').textContent =
                    'Processed ' + offset + ' of ' + total + ' orders (' + pct + '%)';
            }

            function log(msg) {
                var el = document.getElementById('wctqr-log-output');
                el.style.display = 'block';
                el.innerHTML += msg + '\n';
                el.scrollTop = el.scrollHeight;
            }

            function processBatch() {
                if (offset >= total) {
                    document.getElementById('wctqr-progress-text').innerHTML =
                        '<strong style="color:#16a34a">&#10003; Done!</strong> Sent: '+sent+', Failed: '+failed+', Skipped: '+skipped;
                    document.getElementById('wctqr-bulk-done').style.display='block';
                    return;
                }

                var fd = new FormData();
                fd.append('action', 'wctqr_bulk_process');
                fd.append('job_id', jobId);
                fd.append('offset', offset);
                fd.append('batch_size', batchSize);
                fd.append('nonce', nonce);

                fetch(ajaxUrl, {method:'POST', body:fd})
                .then(function(r){return r.json();})
                .then(function(data){
                    if (data.success) {
                        sent    += data.data.sent    || 0;
                        failed  += data.data.failed  || 0;
                        skipped += data.data.skipped || 0;
                        offset  += data.data.processed || batchSize;
                        if (data.data.log) {
                            data.data.log.forEach(function(l){ log(l); });
                        }
                        updateProgress();
                        setTimeout(processBatch, 300);
                    } else {
                        log('Error: ' + (data.data || 'unknown error'));
                        document.getElementById('wctqr-bulk-done').style.display='block';
                    }
                })
                .catch(function(e){
                    log('Network error: '+e.message);
                    document.getElementById('wctqr-bulk-done').style.display='block';
                });
            }

            updateProgress();
            processBatch();
        })();
        </script>
        <?php
    }

    public function ajax_bulk_process() {
        check_ajax_referer( 'wctqr_bulk', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Unauthorized' );

        $job_id     = sanitize_key( $_POST['job_id'] ?? '' );
        $offset     = absint( $_POST['offset'] ?? 0 );
        $batch_size = absint( $_POST['batch_size'] ?? self::BATCH_SIZE );

        $order_ids = get_transient( $job_id );
        if ( ! $order_ids ) wp_send_json_error( 'Job expired or not found' );

        $batch   = array_slice( $order_ids, $offset, $batch_size );
        $retro   = new WCTQR_Retro();
        $sent = $failed = $skipped = 0;
        $log = [];

        foreach ( $batch as $order_id ) {
            $order = wc_get_order( $order_id );
            $label = $order ? 'Order #' . $order_id : 'Order #' . $order_id . ' (not found)';
            $result = $retro->generate_and_send( $order_id );
            if ( $result === false )      { $failed++;  $log[] = '&#10060; ' . $label . ' — failed'; }
            elseif ( $result === null )   { $skipped++; $log[] = '&#9711;  ' . $label . ' — skipped (no ticket products)'; }
            else                          { $sent++;    $log[] = '&#9989;  ' . $label . ' — sent'; }
        }

        wp_send_json_success([
            'sent'      => $sent,
            'failed'    => $failed,
            'skipped'   => $skipped,
            'processed' => count( $batch ),
            'log'       => $log,
        ]);
    }
}

new WCTQR_Bulk();
