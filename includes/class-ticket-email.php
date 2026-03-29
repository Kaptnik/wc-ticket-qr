<?php
/**
 * WCTQR_Ticket_Email
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WCTQR_Ticket_Email {

    private function get_email_ids() {
        $ids = WCTQR_Settings::get( 'wctqr_email_ids' );
        if ( ! is_array($ids) || empty($ids) ) {
            $ids = ['customer_processing_order','customer_completed_order'];
        }
        $ids[] = 'wctqr_ticket'; // always include manual trigger
        return $ids;
    }

    public function __construct() {
        add_filter( 'woocommerce_email_attachments',       [ $this, 'attach_pdfs' ],   10, 3 );
        add_action( 'woocommerce_email_after_order_table', [ $this, 'email_summary' ], 10, 4 );
    }

    private function qr_url( $token ) {
        $upload    = wp_upload_dir();
        $cache_dir = trailingslashit( $upload['basedir'] ) . 'wctqr-tickets/';
        $cache_url = trailingslashit( $upload['baseurl'] ) . 'wctqr-tickets/';
        $filename  = 'qr-' . $token . '.png';
        $filepath  = $cache_dir . $filename;
        $fileurl   = $cache_url . $filename;

        if ( ! file_exists( $filepath ) ) {
            require_once WCTQR_PATH . 'lib/phpqrcode/phpqrcode.php';
            $validate_url = rest_url( 'wctqr/v1/validate/' . $token );
            $png = WCTQR_QRCode::png( $validate_url );
            if ( $png ) file_put_contents( $filepath, $png );
        }

        return file_exists( $filepath ) ? str_replace('http://','https://', $fileurl) : null;
    }

    private function ticket_html( $ticket, $index, $total ) {
        $token_short  = strtoupper( substr( $ticket->token, 0, 8 ) );
        $qr_url       = $this->qr_url( $ticket->token );
        $qr_size      = absint( WCTQR_Settings::get('wctqr_qr_module_size') ?: 300 );
        $footer       = WCTQR_Settings::get('wctqr_email_footer') ?: 'Single-use &bull; Do not share';
        $qty          = isset($ticket->quantity) ? (int)$ticket->quantity : 1;
        $per_order    = WCTQR_Settings::is_per_order_mode();

        // Resolve title placeholders — {admits} = how many people this ticket admits
        $title_tpl = WCTQR_Settings::get('wctqr_ticket_title') ?: 'Admit One';
        $title = str_replace(
            [ '{ticket_number}', '{total_tickets}', '{number_of_items_in_order}', '{admits}' ],
            [ $index + 1,        $total,             $total,                        $qty       ],
            $title_tpl
        );
        $title = esc_html( $title );

        // Label above QR — differs by mode
        $label = $per_order
            ? 'Admits ' . intval($qty) . ' ' . ( $qty === 1 ? 'person' : 'people' )
            : 'Ticket ' . intval($index+1) . ' of ' . intval($total);

        $html  = '<div style="margin:20px 0;padding:24px;background:#f9f9fb;border:2px solid #7c3aed;border-radius:12px;text-align:center;font-family:sans-serif;">';
        $html .= '<p style="margin:0 0 4px;font-size:13px;color:#888;text-transform:uppercase;letter-spacing:0.05em;">' . esc_html($label) . '</p>';
        $html .= '<p style="margin:0 0 16px;font-size:18px;font-weight:bold;color:#1e1e3e;">&#127903; ' . $title . '</p>';

        if ( $qr_url ) {
            $html .= '<img src="' . esc_url($qr_url) . '" width="' . $qr_size . '" height="' . $qr_size . '" alt="Ticket QR Code" style="display:block;margin:0 auto;max-width:100%;border:4px solid #fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.12);" />';
        } else {
            $html .= '<p style="color:#c00;font-size:13px;">QR code could not be generated. Use Ref to check in manually.</p>';
        }

        $html .= '<p style="margin:14px 0 0;font-family:monospace;font-size:13px;color:#666;background:#fff;display:inline-block;padding:4px 10px;border-radius:4px;border:1px solid #ddd;">Ref: ' . esc_html($token_short) . '&hellip;</p>';
        $html .= '<p style="margin:10px 0 0;font-size:11px;color:#aaa;">' . wp_kses_post($footer) . '</p>';
        $html .= '</div>';
        return $html;
    }

    public function attach_pdfs( $attachments, $email_id, $order ) {
        if ( ! in_array( $email_id, $this->get_email_ids(), true ) ) return $attachments;
        if ( ! is_a( $order, 'WC_Order' ) ) return $attachments;

        global $wpdb;
        $tickets = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ticket_qr WHERE order_id = %d AND voided = 0",
            $order->get_id()
        ) );
        if ( empty($tickets) ) return $attachments;

        $pdf_gen = new WCTQR_PDF_Generator();
        foreach ( $tickets as $ticket ) {
            $items = $order->get_items();
            $item  = isset($items[$ticket->order_item_id]) ? $items[$ticket->order_item_id] : null;
            if ( ! $item ) continue;
            $pdf_path = $pdf_gen->generate( $order, $ticket, $item );
            if ( $pdf_path ) $attachments[] = $pdf_path;
        }
        return $attachments;
    }

    public function email_summary( $order, $sent_to_admin, $plain_text, $email ) {
        if ( $plain_text || $sent_to_admin ) return;
        if ( ! in_array( $email->id, $this->get_email_ids(), true ) ) return;

        global $wpdb;
        $tickets = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ticket_qr WHERE order_id = %d AND voided = 0",
            $order->get_id()
        ) );
        if ( empty($tickets) ) return;

        $count = count($tickets);
        echo '<div style="margin:24px 0;padding:16px 20px;border-left:4px solid #7c3aed;background:#f5f3ff;border-radius:0 6px 6px 0;">';
        echo '<p style="margin:0 0 6px;font-size:16px;font-weight:bold;color:#1e1e3e;">&#127903; Your ' . intval($count) . ' ' . ($count===1?'ticket is':'tickets are') . ' below</p>';
        echo '<p style="margin:0;color:#4b4b80;font-size:14px;">Each QR code is single-use. Present it at the entrance — screenshot or print this email.</p>';
        echo '</div>';
        foreach ( $tickets as $i => $ticket ) echo $this->ticket_html($ticket,$i,$count);
    }

    public function trigger( $order_id ) {
        $order = wc_get_order($order_id);
        if ( ! $order ) return false;

        global $wpdb;
        $tickets = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ticket_qr WHERE order_id = %d AND voided = 0", $order_id
        ) );

        $subject_tpl = WCTQR_Settings::get('wctqr_email_subject') ?: 'Your ticket(s) for order #{order_id}';
        $subject     = str_replace('{order_id}', $order_id, $subject_tpl);
        $heading     = WCTQR_Settings::get('wctqr_email_heading') ?: 'Here are your tickets!';
        $intro_tpl   = WCTQR_Settings::get('wctqr_email_intro') ?: 'Your ticket(s) for order #{order_id} are below.';
        $intro       = str_replace(['{order_id}','{first_name}'], [$order_id, $order->get_billing_first_name()], $intro_tpl);

        $mailer = WC()->mailer();
        $count  = count($tickets);

        ob_start();
        echo '<p>Hi ' . esc_html($order->get_billing_first_name()) . ',</p>';
        echo '<p>' . wp_kses_post($intro) . '</p>';
        foreach ( $tickets as $i => $ticket ) echo $this->ticket_html($ticket,$i,$count);
        $body_content = ob_get_clean();

        $message     = $mailer->wrap_message($heading, $body_content);
        $attachments = apply_filters('woocommerce_email_attachments',[],'wctqr_ticket',$order);

        return wp_mail(
            $order->get_billing_email(), $subject, $message,
            ['Content-Type: text/html; charset=UTF-8'], $attachments
        );
    }
}

new WCTQR_Ticket_Email();
