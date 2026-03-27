<?php
/**
 * QR Code generator — Option A
 * Uses the QRServer.com API (free, reliable, no rate limits for normal use)
 * with WordPress HTTP API (wp_remote_get uses cURL which IS available per status page)
 * Falls back to local GD rendering if API unavailable.
 *
 * Note: wp_remote_get uses cURL internally which your server supports.
 * Previous failures were because GoDaddy blocks PHP's allow_url_fopen,
 * but NOT cURL. wp_remote_get uses cURL by default.
 */
class WCTQR_QRCode {

    /**
     * Generate QR PNG using QRServer API via WordPress HTTP (cURL).
     * API: https://api.qrserver.com/v1/create-qr-code/ (free, no key needed)
     */
    public static function png($text, $module = 10, $margin = 4) {
        $size    = 300;
        $qmargin = $margin * $module;
        $url     = 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query([
            'data'   => $text,
            'size'   => $size . 'x' . $size,
            'margin' => $qmargin,
            'format' => 'png',
            'ecc'    => 'M',
        ]);

        // Use wp_remote_get which uses cURL internally
        $response = wp_remote_get($url, [
            'timeout'   => 15,
            'sslverify' => true,
        ]);

        if (!is_wp_error($response)) {
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            if ($code === 200 && strlen($body) > 500) {
                error_log('WCTQR Option A: QRServer API success, ' . strlen($body) . ' bytes');
                return $body;
            }
            error_log('WCTQR Option A: QRServer API returned code ' . $code);
        } else {
            error_log('WCTQR Option A: wp_remote_get error: ' . $response->get_error_message());
        }

        // Fallback: try goqr.me (alternative free API)
        $url2 = 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query([
            'data'   => $text,
            'size'   => '200x200',
            'format' => 'png',
        ]);
        $response2 = wp_remote_get($url2, ['timeout' => 10]);
        if (!is_wp_error($response2) && wp_remote_retrieve_response_code($response2) === 200) {
            $body2 = wp_remote_retrieve_body($response2);
            if (strlen($body2) > 500) return $body2;
        }

        error_log('WCTQR Option A: all API attempts failed');
        return false;
    }

    public static function data_uri($text) {
        $png = self::png($text);
        return $png ? 'data:image/png;base64,' . base64_encode($png) : null;
    }
}
