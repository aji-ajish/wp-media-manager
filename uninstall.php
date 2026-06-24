<?php
/**
 * Fired when the plugin is uninstalled (deleted from WP).
 *
 * @package WP_Media_Manager
 */

// Exit if called directly (not from WordPress uninstall API).
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Define constants so Database class can be loaded cleanly.
// We must define BOTH tables here because uninstall.php runs 
// before the main plugin file is loaded.
if ( ! defined( 'WPMM_TABLE_NAME' ) ) {
    define( 'WPMM_TABLE_NAME', 'wpmm_media_entries' );
}

if ( ! defined( 'WPMM_REDIRECT_TABLE_NAME' ) ) {
    define( 'WPMM_REDIRECT_TABLE_NAME', 'wpmm_redirect_rules' );
}

// Load only what we need.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-database.php';

// Drop table and remove options.
WP_Media_Manager\Database::drop_tables();
delete_option( 'wpmm_version' );

// Remove any leftover transients for a complete clean-up.
delete_transient( 'wpmm_activation_error' );
delete_transient( 'wpmm_url_lookup_map' );