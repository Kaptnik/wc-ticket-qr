<?php
/**
 * WCTQR_PDF_Generator
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WCTQR_PDF_Generator {

    /** @var string */
    private $cache_dir;

    public function __construct() {
        $upload          = wp_upload_dir();
        $this->cache_dir = trailingslashit( $upload['basedir'] ) . 'wctqr-tickets/';
        wp_mkdir_p( $this->cache_dir );

        // Allow public access to PNG files (needed for email image URLs)
        // but block directory listing and PHP execution
        if ( ! file_exists( $this->cache_dir . '.htaccess' ) ) {
            @file_put_contents( $this->cache_dir . '.htaccess',
                "Options -Indexes\n" .
                "<Files *.php>\n  Require all denied\n</Files>\n"
            );
        }
    }

    /**
     * Generate a PDF ticket. Returns file path on success, false on any failure.
     * All errors are caught and logged — never fatal.
     */
    public function generate( $order, $ticket, $item ) {
        try {
            return $this->do_generate( $order, $ticket, $item );
        } catch ( Exception $e ) {
            error_log( 'WCTQR PDF Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
            return false;
        } catch ( Error $e ) {
            error_log( 'WCTQR PDF Fatal Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
            return false;
        }
    }

    private function do_generate( $order, $ticket, $item ) {
        $fpdf_path = WCTQR_PATH . 'lib/fpdf/fpdf.php';

        if ( ! file_exists( $fpdf_path ) ) {
            error_log( 'WCTQR: FPDF not found at ' . $fpdf_path );
            return false;
        }

        // Test write permission before attempting PDF generation
        if ( ! is_writable( $this->cache_dir ) ) {
            error_log( 'WCTQR: cache dir not writable: ' . $this->cache_dir );
            return false;
        }

        // Quick write test
        $test_file = $this->cache_dir . 'write-test.tmp';
        if ( @file_put_contents( $test_file, 'test' ) === false ) {
            error_log( 'WCTQR: cannot write to cache dir: ' . $this->cache_dir );
            return false;
        }
        @unlink( $test_file );

        error_log( 'WCTQR: generating PDF for ticket ' . $ticket->id . ' order ' . $order->get_id() );

        require_once $fpdf_path;

        if ( ! class_exists( 'FPDF' ) ) {
            error_log( 'WCTQR: FPDF class not available after require' );
            return false;
        }

        $filename = 'ticket-' . $ticket->token . '.pdf';
        $filepath = $this->cache_dir . $filename;

        if ( file_exists( $filepath ) ) return $filepath;

        $product     = $item->get_product();
        $event_name  = $product ? $product->get_name() : 'Event';
        $event_date  = $product ? (string) $product->get_meta( '_event_date' )  : '';
        $event_venue = $product ? (string) $product->get_meta( '_event_venue' ) : '';
        $buyer_name  = (string) $order->get_formatted_billing_full_name();
        $order_id    = (int) $order->get_id();
        $token_short = strtoupper( substr( $ticket->token, 0, 8 ) );

        // Get QR image — don't let a failure here stop PDF generation
        $qr_path = null;
        try {
            $qr_path = $this->get_qr_image( $ticket->token );
        } catch ( Exception $e ) {
            error_log( 'WCTQR: QR image fetch failed: ' . $e->getMessage() );
        }

        $pdf = new FPDF( 'L', 'mm', array( 210, 99 ) );
        $pdf->SetAutoPageBreak( false );
        $pdf->AddPage();
        $pdf->SetMargins( 0, 0, 0 );

        // Dark background
        $pdf->SetFillColor( 22, 22, 50 );
        $pdf->Rect( 0, 0, 210, 99, 'F' );

        // Top accent stripe
        $pdf->SetFillColor( 124, 58, 237 );
        $pdf->Rect( 0, 0, 210, 4, 'F' );

        // Event name
        $pdf->SetFont( 'Helvetica', 'B', 18 );
        $pdf->SetTextColor( 255, 200, 50 );
        $pdf->SetXY( 10, 12 );
        $pdf->Cell( 125, 10, $this->safe_str( $event_name ), 0, 1 );

        // Date
        $pdf->SetFont( 'Helvetica', '', 10 );
        $pdf->SetTextColor( 180, 180, 230 );
        if ( $event_date ) {
            $pdf->SetX( 10 );
            $pdf->Cell( 125, 7, 'Date:  ' . $this->safe_str( $event_date ), 0, 1 );
        }
        // Venue
        if ( $event_venue ) {
            $pdf->SetX( 10 );
            $pdf->Cell( 125, 7, 'Venue: ' . $this->safe_str( $event_venue ), 0, 1 );
        }

        // Separator
        $pdf->SetDrawColor( 124, 58, 237 );
        $pdf->SetLineWidth( 0.4 );
        $pdf->Line( 10, 50, 132, 50 );

        // Attendee name
        $pdf->SetFont( 'Helvetica', 'B', 13 );
        $pdf->SetTextColor( 255, 255, 255 );
        $pdf->SetXY( 10, 55 );
        $pdf->Cell( 125, 8, $this->safe_str( $buyer_name ), 0, 1 );

        // Order ref
        $pdf->SetFont( 'Helvetica', '', 9 );
        $pdf->SetTextColor( 140, 140, 190 );
        $pdf->SetX( 10 );
        $pdf->Cell( 125, 6, 'Order #' . $order_id . '   Ref: ' . $token_short, 0, 1 );

        // ADMIT ONE box
        $pdf->SetFillColor( 124, 58, 237 );
        $pdf->Rect( 10, 78, 40, 13, 'F' );
        $pdf->SetFont( 'Helvetica', 'B', 11 );
        $pdf->SetTextColor( 255, 255, 255 );
        $pdf->SetXY( 10, 81 );
        $pdf->Cell( 40, 7, 'ADMIT ONE', 0, 0, 'C' );

        // Perforated divider dashes
        $pdf->SetDrawColor( 100, 100, 140 );
        $pdf->SetLineWidth( 0.3 );
        for ( $yy = 3; $yy < 97; $yy += 5 ) {
            $pdf->Line( 142, $yy, 142, $yy + 3 );
        }

        // QR code
        if ( $qr_path && file_exists( $qr_path ) ) {
            $pdf->Image( $qr_path, 149, 8, 54, 54, 'PNG' );
        } else {
            // Fallback: print token text if no QR image
            $pdf->SetFont( 'Helvetica', '', 7 );
            $pdf->SetTextColor( 200, 200, 200 );
            $pdf->SetXY( 144, 20 );
            $pdf->MultiCell( 63, 5, $ticket->token, 0, 'C' );
        }

        $pdf->SetFont( 'Helvetica', '', 8 );
        $pdf->SetTextColor( 160, 160, 210 );
        $pdf->SetXY( 142, 65 );
        $pdf->Cell( 65, 5, 'Scan to validate entry', 0, 1, 'C' );

        $pdf->SetFont( 'Helvetica', 'B', 9 );
        $pdf->SetTextColor( 255, 255, 255 );
        $pdf->SetXY( 142, 72 );
        $pdf->Cell( 65, 5, $token_short, 0, 1, 'C' );

        $pdf->SetFont( 'Helvetica', '', 7 );
        $pdf->SetTextColor( 100, 100, 150 );
        $pdf->SetXY( 142, 80 );
        $pdf->Cell( 65, 5, 'Single-use - Do not share', 0, 1, 'C' );

        // Bottom stripe
        $pdf->SetFillColor( 124, 58, 237 );
        $pdf->Rect( 0, 95, 210, 4, 'F' );

        // Write to file — use 'F' destination
        $pdf->Output( 'F', $filepath );

        if ( file_exists( $filepath ) ) {
            error_log( 'WCTQR: PDF created successfully at ' . $filepath );
            return $filepath;
        } else {
            error_log( 'WCTQR: PDF Output() ran but file not found at ' . $filepath );
            return false;
        }
    }

    public function get_qr_image( $token ) {
        $qr_path = $this->cache_dir . 'qr-' . $token . '.png';
        if ( file_exists( $qr_path ) ) return $qr_path;

        $validate_url = rest_url( 'wctqr/v1/validate/' . $token );

        // Use local pure-PHP QR generator — GD renders PNG, no external calls
        require_once WCTQR_PATH . 'lib/phpqrcode/phpqrcode.php';
        $png = WCTQR_QRCode::png( $validate_url, 8, 4 );
        if ( $png ) {
            file_put_contents( $qr_path, $png );
            return file_exists( $qr_path ) ? $qr_path : false;
        }

        error_log( 'WCTQR: QR PNG generation failed for token ' . substr($token,0,8) );
        return false;
    }

    public static function delete( $token ) {
        $upload   = wp_upload_dir();
        $dir      = trailingslashit( $upload['basedir'] ) . 'wctqr-tickets/';
        $pdf_file = $dir . 'ticket-' . $token . '.pdf';
        $qr_file  = $dir . 'qr-' . $token . '.png';
        if ( file_exists( $pdf_file ) ) @unlink( $pdf_file );
        if ( file_exists( $qr_file ) )  @unlink( $qr_file );
    }

    private function safe_str( $str ) {
        if ( ! is_string( $str ) ) return '';
        $result = @iconv( 'UTF-8', 'windows-1252//TRANSLIT//IGNORE', $str );
        return $result !== false ? $result : preg_replace( '/[^\x20-\x7E]/', '?', $str );
    }
}
