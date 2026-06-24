<?php

/**
 * Admin menu registration and asset enqueueing.
 *
 * @package WP_Media_Manager
 */

namespace WP_Media_Manager;

if (! defined('ABSPATH')) {
	exit;
}

class Admin_Menu
{

	private Database $db;
	private string   $hook_suffix = '';

	public function __construct(Database $db)
	{
		$this->db = $db;
	}

	public function register(): void
	{
		add_action('admin_menu',            [$this, 'add_menu_page']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
	}

	public function add_menu_page(): void
	{
		// Media Manager
		$this->hook_suffix = add_menu_page(
			__('Media Route & Replace', 'wp-media-manager'),
			__('Media Route & Replace', 'wp-media-manager'),
			'manage_options',
			'wp-media-manager', 
			[$this, 'render_page'],
			'dashicons-randomize', 
			25
		);

		// Redirect Rules
		add_submenu_page(
			'wp-media-manager',
			__('Redirect Rules', 'wp-media-manager'),
			__('Redirect Rules', 'wp-media-manager'),
			'manage_options',
			'wpmm-redirect-rules',
			[$this, 'render_redirect_rules_page']
		);
	}

	public function enqueue_assets(string $hook_suffix): void
	{
		// Fix: Safely modified to load assets only if the page name contains 
		// 'wpmm-redirect-rules' or 'wp-media-manager'.
		if (! str_contains($hook_suffix, 'wp-media-manager') && ! str_contains($hook_suffix, 'wpmm-redirect-rules')) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'wpmm-admin',
			WPMM_PLUGIN_URL . 'assets/css/admin.css',
			[],
			WPMM_VERSION
		);

		wp_enqueue_script(
			'wpmm-admin',
			WPMM_PLUGIN_URL . 'assets/js/admin.js',
			['jquery', 'wp-util'],
			WPMM_VERSION,
			true
		);

		wp_localize_script('wpmm-admin', 'wpmmData', [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce('wpmm_nonce'),
			'siteUrl' => home_url('/'),
			'cacheBuster'  => '',
			'i18n'    => [
				'confirmDelete'     => __('Are you sure you want to delete this entry? This cannot be undone.', 'wp-media-manager'),
				'saving'            => __('Saving…', 'wp-media-manager'),
				'deleting'          => __('Deleting…', 'wp-media-manager'),
				'checking'          => __('Checking…', 'wp-media-manager'),
				'selectMedia'       => __('Select Media', 'wp-media-manager'),
				'useSelected'       => __('Use this file', 'wp-media-manager'),
				'noEntries'         => __('No media entries found. Click "Add New" to get started.', 'wp-media-manager'),
				'invalidUrl'        => __('Please enter a valid WordPress media URL.', 'wp-media-manager'),
				'urlNotMedia'       => __("The URL does not belong to this site's media library.", 'wp-media-manager'),
				'duplicateTitle'    => __('File Already Exists', 'wp-media-manager'),
				'btnReplace'        => __('Yes, Replace It', 'wp-media-manager'),
				'btnCancel'         => __('No, Cancel', 'wp-media-manager'),
				'copyUrl'           => __('Copy URL', 'wp-media-manager'),
				'copied'            => __('Copied!', 'wp-media-manager'),
			],
		]);
	}

	public function render_page(): void
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-media-manager'));
		}
		require_once WPMM_PLUGIN_DIR . 'templates/admin-page.php';
	}

	/**
	 * redirect rules for submenu page
	 */
	public function render_redirect_rules_page(): void
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-media-manager'));
		}
		require_once WPMM_PLUGIN_DIR . 'templates/redirect-rules-page.php';
	}
}
