<?php
/**
 * WCTQR_Validator
 * REST API endpoint: POST /wp-json/wctqr/v1/validate/{token}
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WCTQR_Validator {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route( 'wctqr/v1', '/validate/(?P<token>[a-f0-9]{64})', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'validate_ticket' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'token' => [
                    'required'          => true,
                    'validate_callback' => function($v){ return (bool)preg_match('/^[a-f0-9]{64}$/',$v); },
                ],
            ],
        ]);
    }

    public function check_permission() {
        return current_user_can('manage_woocommerce');
    }

    public function validate_ticket( $request ) {
        global $wpdb;
        $token = sanitize_text_field( $request['token'] );
        $table = $wpdb->prefix . 'ticket_qr';

        $ticket = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE token = %s", $token
        ) );

        if ( ! $ticket ) {
            return new WP_REST_Response( ['valid'=>false,'message'=>'Invalid ticket — token not found.'], 404 );
        }

        $details = $this->build_details( $ticket );

        if ( $ticket->voided ) {
            return new WP_REST_Response( array_merge(
                ['valid'=>false,'message'=>'This ticket has been cancelled or refunded.','voided_at'=>$ticket->voided_at],
                $details
            ), 410 );
        }

        if ( $ticket->scanned ) {
            return new WP_REST_Response( array_merge(
                ['valid'=>false,'message'=>'Ticket already used.','scanned_at'=>$ticket->scanned_at],
                $details
            ), 409 );
        }

        // Atomic update
        $now     = current_time('mysql');
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET scanned=1, scanned_at=%s WHERE token=%s AND scanned=0 AND voided=0",
            $now, $token
        ) );

        if ( ! $updated ) {
            return new WP_REST_Response( array_merge(
                ['valid'=>false,'message'=>'Ticket already used (concurrent scan).'],
                $details
            ), 409 );
        }

        $qty = isset($ticket->quantity) ? (int)$ticket->quantity : 1;
        $msg = $qty > 1 ? "Valid — admit {$qty} people." : 'Valid ticket — entry granted.';

        return new WP_REST_Response( array_merge(
            ['valid'=>true,'message'=>$msg,'scanned_at'=>$now],
            $details
        ), 200 );
    }

    /**
     * Build the full details payload from a ticket row.
     * In per-order mode, decodes the stored item summary.
     * In per-ticket mode, looks up the specific line item.
     */
    private function build_details( $ticket ) {
        $qty = isset($ticket->quantity) ? (int)$ticket->quantity : 1;

        $base = [
            'order_id'   => (int)$ticket->order_id,
            'ticket_ref' => strtoupper( substr($ticket->token, 0, 8) ),
            'created_at' => $ticket->created_at,
            'admits'     => $qty,
            'attendee'   => null,
            'email'      => null,
            'event_name' => null,
            'event_date' => null,
            'event_venue'=> null,
            // Per-order: itemized list of all ticket types
            'items'      => null,
            // Per-ticket: single item details
            'product_name' => null,
            'variation'    => null,
            'attributes'   => [],
            'ticket_number'=> null,
        ];

        $order = wc_get_order( $ticket->order_id );
        if ( ! $order ) return $base;

        $base['attendee'] = $order->get_formatted_billing_full_name();
        $base['email']    = $order->get_billing_email();

        // ── Per-order mode: decode stored item summary ────────────────────────
        if ( ! empty($ticket->order_item_summary) ) {
            $summary = json_decode( $ticket->order_item_summary, true );
            if ( is_array($summary) ) {
                $base['items'] = $summary;
                // Pull event meta from first ticket product
                $base = array_merge( $base, $this->get_event_meta_from_order($order) );
                return $base;
            }
        }

        // ── Per-ticket mode: look up the specific line item ───────────────────
        $items = $order->get_items();
        $item  = isset($items[$ticket->order_item_id]) ? $items[$ticket->order_item_id] : null;
        if ( ! $item ) return $base;

        $product = $item->get_product();
        if ( ! $product ) return $base;

        $base['product_name'] = $item->get_name();

        if ( $product->is_type('variation') ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ($parent) $base['product_name'] = $parent->get_name();

            $attrs = [];
            foreach ( $product->get_variation_attributes() as $k => $v ) {
                $label = wc_attribute_label( str_replace('attribute_','',$k), $product );
                if ( taxonomy_exists(str_replace('attribute_','',$k)) ) {
                    $term = get_term_by('slug',$v,str_replace('attribute_','',$k));
                    if ($term) $v = $term->name;
                }
                $attrs[$label] = $v;
            }
            $base['attributes'] = $attrs;
            $base['variation']  = implode(' / ', array_values($attrs));
        }

        $meta_product = ($product->is_type('variation') && $product->get_parent_id())
            ? wc_get_product($product->get_parent_id()) : $product;
        if ($meta_product) {
            $base['event_date']  = $meta_product->get_meta('_event_date')  ?: null;
            $base['event_venue'] = $meta_product->get_meta('_event_venue') ?: null;
            $base['event_name']  = $meta_product->get_name();
        }

        // Ticket number within order item
        global $wpdb;
        $all = $wpdb->get_col($wpdb->prepare(
            "SELECT token FROM {$wpdb->prefix}ticket_qr WHERE order_id=%d AND order_item_id=%d ORDER BY id ASC",
            $ticket->order_id, $ticket->order_item_id
        ));
        $idx = array_search($ticket->token, $all);
        if ($idx !== false && count($all) > 1) {
            $base['ticket_number'] = 'Ticket '.($idx+1).' of '.count($all);
        }

        return $base;
    }

    private function get_event_meta_from_order( $order ) {
        $meta = ['event_name'=>null,'event_date'=>null,'event_venue'=>null];
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) continue;
            $p = ($product->is_type('variation') && $product->get_parent_id())
                ? wc_get_product($product->get_parent_id()) : $product;
            if ( ! $p ) continue;
            $meta['event_name']  = $meta['event_name']  ?: $p->get_name();
            $meta['event_date']  = $meta['event_date']  ?: ($p->get_meta('_event_date')  ?: null);
            $meta['event_venue'] = $meta['event_venue'] ?: ($p->get_meta('_event_venue') ?: null);
            break;
        }
        return $meta;
    }
}

new WCTQR_Validator();
