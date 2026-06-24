<?php

/**
 * Plugin Name:       Media Route & Replace
 * Description:       Create clean custom media paths, seamlessly replace files without breaking links, and manage powerful 301/302/404 redirect rules.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Ajish S
 * License:           GPL v2 or later
 * Text Domain:       wp-media-manager
 * Domain Path:       /languages
 *
 * @package WP_Media_Manager
 */

if (! defined('ABSPATH')) {
	exit;
}

define('WPMM_VERSION',         '1.0.0');
define('WPMM_PLUGIN_FILE',     __FILE__);
define('WPMM_PLUGIN_DIR',      plugin_dir_path(__FILE__));
define('WPMM_PLUGIN_URL',      plugin_dir_url(__FILE__));
define('WPMM_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('WPMM_TABLE_NAME',      'wpmm_media_entries');
define('WPMM_REDIRECT_TABLE_NAME',  'wpmm_redirect_rules');
define('WPMM_QUERY_VAR',       'wpmm_file_request');

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * BOOTSTRAP ORDER — do NOT reorder
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * 1. Manually require the files needed BEFORE plugins_loaded fires.
 *    register_activation_hook() runs before plugins_loaded, so the
 *    autoloader (step 3) is not yet active when activation fires.
 *    Using require_once with WPMM_PLUGIN_DIR is the safest pattern.
 *
 * 2. Register activation / deactivation hooks using closures so the
 *    callback is always resolvable even on multisite.
 *
 * 3. Register PSR-4 autoloader for all other classes.
 *
 * 4. Boot the singleton on plugins_loaded so inter-plugin hooks work.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// Step 1 — manual requires (autoloader not yet active).
require_once WPMM_PLUGIN_DIR . 'includes/class-database.php';
require_once WPMM_PLUGIN_DIR . 'includes/class-activator.php';
require_once WPMM_PLUGIN_DIR . 'includes/class-deactivator.php';

// Step 2 — lifecycle hooks.
register_activation_hook(
	__FILE__,
	static function (): void {
		WP_Media_Manager\Activator::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		WP_Media_Manager\Deactivator::deactivate();
	}
);

// Step 3 — autoloader.
spl_autoload_register(static function (string $class_name): void {
	$prefix   = 'WP_Media_Manager\\';
	$base_dir = WPMM_PLUGIN_DIR . 'includes/';

	if (strncmp($prefix, $class_name, strlen($prefix)) !== 0) {
		return;
	}

	$relative = substr($class_name, strlen($prefix));
	$file     = $base_dir . 'class-' . strtolower(
		str_replace(['\\', '_'], ['/', '-'], $relative)
	) . '.php';

	if (file_exists($file)) {
		require $file;
	}
});

// Step 4 — boot.
/**
 * Returns the main plugin singleton.
 *
 * @return WP_Media_Manager\Plugin
 */
function wpmm(): WP_Media_Manager\Plugin
{
	return WP_Media_Manager\Plugin::get_instance();
}

add_action('plugins_loaded', 'wpmm');

