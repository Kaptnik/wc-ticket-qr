<?php
/**
 * WCTQR_Validator
 *
 * REST API endpoint for ticket scanning.
 * Endpoint: POST /wp-json/wctqr/v1/validate/{token}
 * Auth:     Requires a logged-in user with manage_woocommerce capability.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WCTQR_Validator {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route(
            'wctqr/v1',
            '/validate/(?P<token>[a-f0-9]{64})',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'validate_ticket' ],
                'permission_callback' => [ $this, 'check_permission' ],
                'args'                => [
                    'token' => [
                        'required'          => true,
                        'validate_callback' => function( $v ) {
                            return (bool) preg_match( '/^[a-f0-9]{64}$/', $v );
                        },
                    ],
                ],
            ]
        );
    }

    public function check_permission() {
        return current_user_can( 'manage_woocommerce' );
    }

    public function validate_ticket( $request ) {
        global $wpdb;

        $token  = sanitize_text_field( $request['token'] );
        $table  = $wpdb->prefix . 'ticket_qr';

        $ticket = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE token = %s",
            $token
        ) );

        // Unknown token
        if ( ! $ticket ) {
            return new WP_REST_Response( [
                'valid'   => false,
                'message' => 'Invalid ticket — token not found.',
            ], 404 );
        }

        // Build ticket details (available even for rejected tickets)
        $details = $this->get_ticket_details( $ticket );

        // Voided
        if ( $ticket->voided ) {
            return new WP_REST_Response( array_merge( [
                'valid'     => false,
                'message'   => 'This ticket has been cancelled or refunded.',
                'voided_at' => $ticket->voided_at,
            ], $details ), 410 );
        }

        // Already scanned
        if ( $ticket->scanned ) {
            return new WP_REST_Response( array_merge( [
                'valid'      => false,
                'message'    => 'Ticket already used.',
                'scanned_at' => $ticket->scanned_at,
            ], $details ), 409 );
        }

        // Atomic update — WHERE scanned = 0 prevents race conditions
        $now     = current_time( 'mysql' );
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET scanned = 1, scanned_at = %s
             WHERE token = %s AND scanned = 0 AND voided = 0",
            $now,
            $token
        ) );

        if ( ! $updated ) {
            return new WP_REST_Response( array_merge( [
                'valid'   => false,
                'message' => 'Ticket already used (concurrent scan detected).',
            ], $details ), 409 );
        }

        return new WP_REST_Response( array_merge( [
            'valid'      => true,
            'message'    => 'Valid ticket — entry granted.',
            'scanned_at' => $now,
        ], $details ), 200 );
    }

    /**
     * Build a rich details array from the ticket DB row.
     */
    private function get_ticket_details( $ticket ) {
        $details = [
            'order_id'     => (int) $ticket->order_id,
            'ticket_ref'   => strtoupper( substr( $ticket->token, 0, 8 ) ),
            'created_at'   => $ticket->created_at,
            'attendee'     => null,
            'email'        => null,
            'product_name' => null,
            'variation'    => null,
            'attributes'   => [],
            'event_date'   => null,
            'event_venue'  => null,
            'quantity_in_order' => null,
            'ticket_number'    => null,  // e.g. "Ticket 2 of 3"
        ];

        $order = wc_get_order( $ticket->order_id );
        if ( ! $order ) return $details;

        // Attendee / billing info
        $details['attendee'] = $order->get_formatted_billing_full_name();
        $details['email']    = $order->get_billing_email();

        // Line item
        $items = $order->get_items();
        $item  = isset( $items[ $ticket->order_item_id ] ) ? $items[ $ticket->order_item_id ] : null;
        if ( ! $item ) return $details;

        $product = $item->get_product();
        if ( ! $product ) return $details;

        // Product / variation name
        $details['product_name'] = $item->get_name(); // WC already appends variation to name

        // Variation details
        if ( $product->is_type( 'variation' ) ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent ) {
                $details['product_name'] = $parent->get_name();
            }

            // Get variation attributes as human-readable key: value pairs
            $attributes     = $product->get_variation_attributes();
            $taxonomy_attrs = [];
            foreach ( $attributes as $attr_key => $attr_value ) {
                // Convert taxonomy slugs to readable labels
                $label = wc_attribute_label( str_replace( 'attribute_', '', $attr_key ), $product );
                if ( taxonomy_exists( str_replace( 'attribute_', '', $attr_key ) ) ) {
                    $term = get_term_by( 'slug', $attr_value, str_replace( 'attribute_', '', $attr_key ) );
                    $attr_value = $term ? $term->name : $attr_value;
                }
                $taxonomy_attrs[ $label ] = $attr_value;
            }
            $details['attributes'] = $taxonomy_attrs;

            // Variation label (e.g. "Adult / Saturday")
            $details['variation'] = implode( ' / ', array_values( $taxonomy_attrs ) );
        }

        // Event meta from parent product
        $meta_product = ( $product->is_type( 'variation' ) && $product->get_parent_id() )
            ? wc_get_product( $product->get_parent_id() )
            : $product;

        if ( $meta_product ) {
            $details['event_date']  = $meta_product->get_meta( '_event_date' )  ?: null;
            $details['event_venue'] = $meta_product->get_meta( '_event_venue' ) ?: null;
        }

        // Ticket number within the order (e.g. "Ticket 2 of 3")
        $qty = (int) $item->get_quantity();
        $details['quantity_in_order'] = $qty;

        if ( $qty > 1 ) {
            // Work out which ticket index this token is
            global $wpdb;
            $all_tokens = $wpdb->get_col( $wpdb->prepare(
                "SELECT token FROM {$wpdb->prefix}ticket_qr
                 WHERE order_id = %d AND order_item_id = %d
                 ORDER BY id ASC",
                $ticket->order_id,
                $ticket->order_item_id
            ) );
            $index = array_search( $ticket->token, $all_tokens );
            if ( $index !== false ) {
                $details['ticket_number'] = 'Ticket ' . ( $index + 1 ) . ' of ' . count( $all_tokens );
            }
        }

        return $details;
    }
}

new WCTQR_Validator();
