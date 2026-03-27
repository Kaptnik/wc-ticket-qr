<?php
/**
 * Uninstall WooCommerce Ticket QR
 *
 * This file is called automatically by WordPress when the plugin is deleted
 * via the Plugins screen. It removes all plugin data from the database and
 * cleans up uploaded files.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;

// Drop the custom table
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ticket_qr" );

// Remove plugin options
delete_option( 'wctqr_db_version' );

// Remove the ticket cache directory
$upload_dir  = wp_upload_dir();
$ticket_dir  = trailingslashit( $upload_dir['basedir'] ) . 'wctqr-tickets/';

if ( is_dir( $ticket_dir ) ) {
    $files = glob( $ticket_dir . '*', GLOB_MARK );
    if ( $files ) {
        foreach ( $files as $file ) {
            if ( ! is_dir( $file ) ) {
                @unlink( $file );
            }
        }
    }
    @rmdir( $ticket_dir );
}
