<?php
/**
 * WCTQR_QR
 *
 * Generates QR codes entirely server-side.
 *
 * Priority:
 *  1. phpqrcode library (qrlib.php) — if installed, produces PNG
 *  2. Built-in pure-PHP QR encoder — produces PNG via GD (available on all hosts)
 *
 * Returns a base64-encoded PNG data URI for embedding in emails.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WCTQR_QR {

    /**
     * Generate a QR code and return a data:image/png;base64,... URI.
     *
     * @param  string $data   The text/URL to encode.
     * @param  int    $size   Output image size in pixels.
     * @return string|false   Data URI string, or false on failure.
     */
    public static function data_uri( $data, $size = 250 ) {

        // ── Option 1: phpqrcode library ───────────────────────────────────
        $qrlib = WCTQR_PATH . 'lib/phpqrcode/qrlib.php';
        if ( file_exists( $qrlib ) ) {
            try {
                require_once $qrlib;
                if ( class_exists( 'QRcode' ) ) {
                    $tmp = tempnam( sys_get_temp_dir(), 'wctqr' ) . '.png';
                    QRcode::png( $data, $tmp, QR_ECLEVEL_M, 6, 2 );
                    if ( file_exists( $tmp ) ) {
                        $b64 = base64_encode( file_get_contents( $tmp ) );
                        @unlink( $tmp );
                        return 'data:image/png;base64,' . $b64;
                    }
                }
            } catch ( Exception $e ) {
                error_log( 'WCTQR: phpqrcode failed: ' . $e->getMessage() );
            }
        }

        // ── Option 2: Built-in encoder using GD ──────────────────────────
        if ( extension_loaded( 'gd' ) ) {
            return self::generate_with_gd( $data, $size );
        }

        error_log( 'WCTQR: No QR generation method available (no phpqrcode, no GD)' );
        return false;
    }

    /**
     * Pure PHP QR encoder that outputs via GD.
     * Implements a simplified QR Code (alphanumeric/byte mode, version 3-10).
     */
    private static function generate_with_gd( $data, $size ) {
        // Use a QR encoding algorithm implemented in pure PHP
        // We implement this using the Reed-Solomon approach for small payloads
        $matrix = self::encode_qr( $data );
        if ( ! $matrix ) return false;

        $modules   = count( $matrix );
        $quiet     = 4; // quiet zone in modules
        $total     = $modules + $quiet * 2;
        $module_px = max( 2, (int) floor( $size / $total ) );
        $img_size  = $total * $module_px;

        $img   = imagecreatetruecolor( $img_size, $img_size );
        $white = imagecolorallocate( $img, 255, 255, 255 );
        $black = imagecolorallocate( $img, 0, 0, 0 );

        imagefill( $img, 0, 0, $white );

        for ( $row = 0; $row < $modules; $row++ ) {
            for ( $col = 0; $col < $modules; $col++ ) {
                if ( $matrix[ $row ][ $col ] ) {
                    $x1 = ( $quiet + $col ) * $module_px;
                    $y1 = ( $quiet + $row ) * $module_px;
                    $x2 = $x1 + $module_px - 1;
                    $y2 = $y1 + $module_px - 1;
                    imagefilledrectangle( $img, $x1, $y1, $x2, $y2, $black );
                }
            }
        }

        ob_start();
        imagepng( $img );
        $png = ob_get_clean();
        imagedestroy( $img );

        return 'data:image/png;base64,' . base64_encode( $png );
    }

    /**
     * Minimal QR Code matrix encoder.
     * Uses the php-qrcode-detector-decoder algorithm for byte mode encoding.
     * Supports QR versions 1-10 which covers URLs up to ~200 chars.
     */
    private static function encode_qr( $data ) {
        // We delegate to a well-tested compact implementation
        // This uses the BaconQrCode algorithm simplified for our use case
        require_once WCTQR_PATH . 'includes/class-ticket-qr-encoder.php';
        return WCTQR_QR_Encoder::encode( $data );
    }
}
