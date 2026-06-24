<?php

/**
 * AJAX request handlers.
 *
 * @package WP_Media_Manager
 */

namespace WP_Media_Manager;

if (! defined('ABSPATH')) {
	exit;
}

class Ajax_Handler
{

	private Database $db;
	private Media_Handler $media;

	public function __construct(Database $db, Media_Handler $media)
	{
		$this->db    = $db;
		$this->media = $media;
	}

	public function register(): void
	{
		$actions = [
			'wpmm_add_entry'       => 'handle_add',
			'wpmm_update_entry'    => 'handle_update',
			'wpmm_delete_entry'    => 'handle_delete',
			'wpmm_get_entry'       => 'handle_get',
			'wpmm_get_entries'     => 'handle_get_entries',
			'wpmm_resolve_url'     => 'handle_resolve_url',
			'wpmm_check_duplicate' => 'handle_check_duplicate',
			'wpmm_replace_entry'   => 'handle_replace_entry',
			'wpmm_save_redirect_rule'   => 'handle_save_redirect_rule',
			'wpmm_get_redirect_rules'   => 'handle_get_redirect_rules',
			'wpmm_delete_redirect_rule' => 'handle_delete_redirect_rule',
			'wpmm_replace_media_file' => 'handle_replace_media_file',
		];

		foreach ($actions as $action => $method) {
			add_action("wp_ajax_{$action}", [$this, $method]);
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Handlers
	// ─────────────────────────────────────────────────────────────────────────

	public function handle_add(): void
	{
		Helper::verify_nonce($this->post_string('nonce'), 'wpmm_nonce');
		Helper::check_capability();

		$data = $this->extract_entry_data();

		if (empty($data['original_url'])) {
			wp_send_json_error(['message' => __('A media URL is required.', 'wp-media-manager')]);
		}

		
		$existing_mapping = $this->db->find_by_original_url($data['original_url']);
		if ($existing_mapping) {
			$existing_file_ext = strtolower(pathinfo(wp_basename($existing_mapping->original_url), PATHINFO_EXTENSION));
			$existing_effective = $existing_mapping->include_extension ? $existing_mapping->custom_name . '.' . $existing_file_ext : $existing_mapping->custom_name;
			$full_path = trim($existing_mapping->custom_path . '/' . $existing_effective, '/');
			
			wp_send_json_error([
				'message' => sprintf(__('This media file is already linked to the custom path "%s". The same media file cannot be added again as a new entry.', 'wp-media-manager'), $full_path)
			]);
		}

		// Extension-aware duplicate check.
		// On collision we return SUCCESS (not error) with is_duplicate=true so
		// the JS can open the replace confirmation modal. Returning an error
		// would cause doSaveRequest() to show a toast and swallow the event.
		$dup = $this->check_extension_aware_duplicate(
			$data['custom_path'],
			$data['custom_name'],
			(bool) $data['include_extension'],
			$data['original_url']
		);

		if ($dup) {
			wp_send_json_success([
				'is_duplicate' => true,
				'duplicate_id' => $dup['id'],
				'message'      => $dup['message'],
			]);
		}

		$id = $this->db->insert($data);

		if (false === $id) {
			wp_send_json_error(['message' => __('Failed to save entry. Please try again.', 'wp-media-manager')]);
		}

		$entry = $this->db->get($id);
		wp_send_json_success([
			'is_duplicate' => false,
			'message'      => __('Entry saved successfully.', 'wp-media-manager'),
			'entry'        => $this->format_entry($entry),
		]);
	}

	public function handle_update(): void
	{
		Helper::verify_nonce($this->post_string('nonce'), 'wpmm_nonce');
		Helper::check_capability();

		$id = absint($this->post_string('id'));
		if (! $id || ! $this->db->get($id)) {
			wp_send_json_error(['message' => __('Entry not found.', 'wp-media-manager')]);
		}

		$data = $this->extract_entry_data();

		$existing_mapping = $this->db->find_by_original_url($data['original_url']);
		if ($existing_mapping && (int) $existing_mapping->id !== $id) {
			$existing_file_ext = strtolower(pathinfo(wp_basename($existing_mapping->original_url), PATHINFO_EXTENSION));
			$existing_effective = $existing_mapping->include_extension ? $existing_mapping->custom_name . '.' . $existing_file_ext : $existing_mapping->custom_name;
			$full_path = trim($existing_mapping->custom_path . '/' . $existing_effective, '/');
			
			wp_send_json_error([
				'message' => sprintf(__('This media file has already been used in another custom path, "%s"', 'wp-media-manager'), $full_path)
			]);
		}

		// Duplicate check excluding self so re-saving same entry doesn't flag.
		$dup = $this->check_extension_aware_duplicate(
			$data['custom_path'],
			$data['custom_name'],
			(bool) $data['include_extension'],
			$data['original_url'],
			$id
		);

		if ($dup) {
			// Return SUCCESS so JS opens the replace modal (not an error toast).
			wp_send_json_success([
				'is_duplicate' => true,
				'duplicate_id' => $dup['id'],
				'message'      => $dup['message'],
			]);
		}

		if (! $this->db->update($id, $data)) {
			wp_send_json_error(['message' => __('Failed to update entry.', 'wp-media-manager')]);
		}

		$entry = $this->db->get($id);
		wp_send_json_success([
			'is_duplicate' => false,
			'message'      => __('Entry updated successfully.', 'wp-media-manager'),
			'entry'        => $this->format_entry($entry),
		]);
	}

	public function handle_delete(): void
	{
		Helper::verify_nonce($this->post_string('nonce'), 'wpmm_nonce');
		Helper::check_capability();

		$id = absint($this->post_string('id'));
		if (! $id) {
			wp_send_json_error(['message' => __('Invalid entry ID.', 'wp-media-manager')]);
		}

		if (! $this->db->delete($id)) {
			wp_send_json_error(['message' => __('Failed to delete entry.', 'wp-media-manager')]);
		}

		wp_send_json_success(['message' => __('Entry deleted successfully.', 'wp-media-manager')]);
	}

	public function handle_get(): void
	{
		Helper::verify_nonce($this->post_string('nonce'), 'wpmm_nonce');
		Helper::check_capability();

		$id    = absint($this->post_string('id'));
		$entry = $id ? $this->db->get($id) : null;

		if (! $entry) {
			wp_send_json_error(['message' => __('Entry not found.', 'wp-media-manager')]);
		}

		wp_send_json_success(['entry' => $this->format_entry($entry)]);
	}

	public function handle_get_entries(): void
	{
		Helper::verify_nonce($this->post_string('nonce'), 'wpmm_nonce');
		Helper::check_capability();

		$args = [
			'search'   => sanitize_text_field($this->post_string('search')),
			'per_page' => max(1, absint($this->post_string('per_page') ?: 20)),
			'page'     => max(1, absint($this->post_string('page') ?: 1)),
		];

		$entries = $this->db->get_all($args);
		$total   = $this->db->count($args['search']);

		wp_send_json_success([
			'entries'     => array_map([$this, 'format_entry'], $entries),
			'total'       => $total,
			'total_pages' => (int) ceil($total / $args['per_page']),
			'page'        => $args['page'],
		]);
	}

	public function handle_resolve_url(): void
	{
		Helper::verify_nonce($this->post_string('nonce'), 'wpmm_nonce');
		Helper::check_capability();

		$url = esc_url_raw($this->post_string('url'));

		if (! $url) {
			wp_send_json_error(['message' => __('Invalid URL.', 'wp-media-manager')]);
		}

		if (! $this->media->is_local_media_url($url)) {
			wp_send_json_error(['message' => __("URL does not belong to this site's media library.", 'wp-media-manager')]);
		}

		$attachment_id = $this->media->get_attachment_id_from_url($url);

		if (! $attachment_id) {
			wp_send_json_success([
				'resolved'      => false,
				'original_url'  => $url,
				'attachment_id' => 0,
				'filename'      => wp_basename($url),
				'file_type'     => 'other',
				'thumbnail'     => null,
			]);
		}

		$data = $this->media->get_attachment_data($attachment_id);
		wp_send_json_success(array_merge(['resolved' => true], $data));
	}

	/**
	 * Extension-aware duplicate check AJAX endpoint.
	 *
	 * Rules:
	 *  1. extension OFF + same path + same name → duplicate regardless.
	 *  2. extension ON + same path + same name + same extension → duplicate.
	 *  3. extension ON + same path + same name + DIFFERENT extension → allowed.
	 *
	 * @return void
	 */
	public function handle_check_duplicate(): void
	{
		Helper::verify_nonce($this->post_string('nonce'), 'wpmm_nonce');
		Helper::check_capability();

		$custom_path      = trim(sanitize_text_field($this->post_string('custom_path')), '/');
		$custom_name      = Helper::sanitize_custom_name($this->post_string('custom_name'));
		$include_ext      = (bool) $this->post_string('include_extension');
		$original_url     = $this->post_string('original_url');
		$exclude_id       = absint($this->post_string('exclude_id')) ?: null;

		if ('' === $custom_name) {
			wp_send_json_success(['is_duplicate' => false]);
		}

		$dup = $this->check_extension_aware_duplicate(
			$custom_path,
			$custom_name,
			$include_ext,
			$original_url,
			$exclude_id
		);

		if ($dup) {
			wp_send_json_success([
				'is_duplicate' => true,
				'duplicate_id' => $dup['id'],
				'message'      => $dup['message'],
			]);
		}

		wp_send_json_success(['is_duplicate' => false]);
	}

	public function handle_replace_entry(): void
	{
		Helper::verify_nonce($this->post_string('nonce'), 'wpmm_nonce');
		Helper::check_capability();

		$target_id = absint($this->post_string('target_id'));
		if (! $target_id || ! $this->db->get($target_id)) {
			wp_send_json_error(['message' => __('Target entry not found.', 'wp-media-manager')]);
		}

		$data = $this->extract_entry_data();

		if (empty($data['original_url'])) {
			wp_send_json_error(['message' => __('A media URL is required.', 'wp-media-manager')]);
		}

		if (! $this->db->replace_entry($target_id, $data)) {
			wp_send_json_error(['message' => __('Failed to replace entry. Please try again.', 'wp-media-manager')]);
		}

		$entry = $this->db->get($target_id);
		wp_send_json_success([
			'message' => __('Entry replaced successfully.', 'wp-media-manager'),
			'entry'   => $this->format_entry($entry),
		]);
	}

	public function handle_save_redirect_rule(): void
	{
		Helper::verify_nonce($this->post_string('nonce'), 'wpmm_nonce');
		Helper::check_capability();

		global $wpdb;
		$table = $wpdb->prefix . WPMM_REDIRECT_TABLE_NAME;

		$id            = absint($this->post_string('id'));
		$raw_source  = trim($this->post_string('source_path'));
		$source_path = str_starts_with($raw_source, 'http') ? esc_url_raw($raw_source) : trim(sanitize_text_field($raw_source), '/ ');
		$target_url    = esc_url_raw(trim($this->post_string('target_url')));
		$redirect_type = absint($this->post_string('redirect_type'));

		if (empty($source_path)) {
			wp_send_json_error(['message' => __('Source path is required.', 'wp-media-manager')]);
		}

		$data = [
			'source_path'   => $source_path,
			'target_url'    => $target_url,
			'redirect_type' => $redirect_type,
			'updated_at'    => current_time('mysql', true),
		];

		if ($id) {
			$wpdb->update($table, $data, ['id' => $id], ['%s', '%s', '%d', '%s'], ['%d']);
			$msg = __('Redirect rule updated successfully.', 'wp-media-manager');
		} else {
			$data['created_at'] = current_time('mysql', true);
			$result = $wpdb->insert($table, $data, ['%s', '%s', '%d', '%s', '%s']);
			if (false === $result) {
				wp_send_json_error(['message' => __('Source path must be unique.', 'wp-media-manager')]);
			}
			$msg = __('Redirect rule created successfully.', 'wp-media-manager');
		}

		wp_send_json_success(['message' => $msg]);
	}

	/**
	 * fetch all redirect rules for admin listing.
	 */
	public function handle_get_redirect_rules(): void
	{
		Helper::verify_nonce($this->post_string('nonce'), 'wpmm_nonce');
		Helper::check_capability();

		global $wpdb;
		$table = $wpdb->prefix . WPMM_REDIRECT_TABLE_NAME;

		$rules = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC");

		wp_send_json_success(['rules' => $rules]);
	}

	/**
	 * Delete a redirect rule.
	 */
	public function handle_delete_redirect_rule(): void
	{
		Helper::verify_nonce($this->post_string('nonce'), 'wpmm_nonce');
		Helper::check_capability();

		global $wpdb;
		$table = $wpdb->prefix . WPMM_REDIRECT_TABLE_NAME;

		$id = absint($this->post_string('id'));
		$wpdb->delete($table, ['id' => $id], ['%d']);

		wp_send_json_success(['message' => __('Redirect rule deleted successfully.', 'wp-media-manager')]);
	}

	/**
	 * Replace the old media file with a system binary file via AJAX at the same URL.
	 */
	/**
	 * Replace media file via AJAX with advanced options (Just Replace / Rename / Change Date).
	 */
	public function handle_replace_media_file(): void
	{
		check_ajax_referer('wpmm_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Permission denied.', 'wp-media-manager')]);
		}

		$current_id     = $current_id = absint($this->post_string('current_attachment_id'));
		$replace_method = sanitize_text_field($_POST['replace_method'] ?? 'just_replace'); // just_replace OR replace_and_rename
		$date_method    = sanitize_text_field($_POST['date_method'] ?? 'keep_date');       // keep_date OR current_date
		$uploaded_file  = $_FILES['replacement_file'] ?? null;

		if (! $current_id || ! $uploaded_file) {
			wp_send_json_error(['message' => __('Invalid data or file missing.', 'wp-media-manager')]);
		}

		$old_file_path = get_attached_file($current_id);
		if (! file_exists($old_file_path)) {
			wp_send_json_error(['message' => __('The old file was not found on the server.', 'wp-media-manager')]);
		}

		$old_dir  = dirname($old_file_path);
		$old_name = basename($old_file_path);
		$old_ext  = strtolower(pathinfo($old_name, PATHINFO_EXTENSION));
		$new_ext  = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));

		if ($old_ext !== $new_ext) {
			wp_send_json_error(['message' => sprintf(__('File extensions must match. Old: %s, New: %s', 'wp-media-manager'), $old_ext, $new_ext)]);
		}

		// 1. Delete all old thumbnails from the server
		$old_meta = wp_get_attachment_metadata($current_id);
		if (! empty($old_meta['sizes'])) {
			foreach ($old_meta['sizes'] as $size => $size_info) {
				$thumb_path = $old_dir . '/' . $size_info['file'];
				if (file_exists($thumb_path)) {
					@unlink($thumb_path);
				}
				if (file_exists($thumb_path . '.webp')) {
					@unlink($thumb_path . '.webp');
				}
			}
		}
		if (file_exists($old_file_path . '.webp')) {
			@unlink($old_file_path . '.webp');
		}

		// 2. Determine the new target directory and file name
		$target_dir  = $old_dir;
		$target_name = $old_name; // If 'just_replace', the old name will be kept

		if ('replace_and_rename' === $replace_method) {
			$target_name = sanitize_file_name($uploaded_file['name']);
		}

		// If the user selected to update the date to the current date
		if ('current_date' === $date_method) {
			$wp_upload_dir = wp_upload_dir(); // Current month upload folder (e.g. 2026/05)
			$target_dir    = $wp_upload_dir['path'];

			// Creates the folder for the new date if it does not exist on the server
			if (! file_exists($target_dir)) {
				wp_mkdir_p($target_dir);
			}

			// Completely deletes the original file from the old folder
			@unlink($old_file_path);
		}

		$destination_file = $target_dir . '/' . $target_name;

		// 3. Overwrite the new file on the server or move it to the new folder
		if (move_uploaded_file($uploaded_file['tmp_name'], $destination_file)) {

			require_once ABSPATH . 'wp-admin/includes/image.php';

			// Update the WordPress attachment path to the new destination
			update_attached_file($current_id, $destination_file);

			// If the date has changed, update the attachment post date in the WordPress database
			if ('current_date' === $date_method) {
				global $wpdb;
				$current_time = current_time('mysql');
				$wpdb->update(
					$wpdb->posts,
					[
						'post_date'     => $current_time,
						'post_date_gmt' => get_gmt_from_date($current_time)
					],
					['ID' => $current_id]
				);
			}

			// 4. Generate new thumbnails and metadata
			$attach_data = wp_generate_attachment_metadata($current_id, $destination_file);
			wp_update_attachment_metadata($current_id, $attach_data);

			// 5. If 'replace_and_rename', update the name and URL in our custom Media Manager table
			if ('replace_and_rename' === $replace_method) {
				$new_custom_name = pathinfo($target_name, PATHINFO_FILENAME);
				$wpdb->update(
					$wpdb->prefix . WPMM_TABLE_NAME,
					[
						'custom_name'  => $new_custom_name,
						'original_url' => wp_get_attachment_url($current_id)
					],
					['attachment_id' => $current_id]
				);
			}

			// Update WebP and third-party image converters
			if (function_exists('delete_post_meta')) {
				delete_post_meta($current_id, 'ewww_image_optimizer_webp_status');
				delete_post_meta($current_id, '_wp_attachment_wp-smush-images');
				do_action('wp_generate_attachment_metadata', $attach_data, $current_id);
			}

			clean_post_cache($current_id);

        	delete_transient('wpmm_url_lookup_map');

			wp_send_json_success([
				'message'   => 'File replaced successfully with selected options.',
				'timestamp' => time()
			]);
		} else {
			wp_send_json_error(['message' => __('Failed to upload and overwrite the file on the server.', 'wp-media-manager')]);
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Duplicate detection
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Extension-aware duplicate check.
	 *
	 * RULES (final, confirmed):
	 *
	 *  Case A — Incoming ext=OFF (/image/home):
	 *    → Duplicate if ANY entry at (path, name) exists, regardless of
	 *      that entry's extension mode.
	 *    Reason: /image/home is a unique URL. Only one entry can own it.
	 *    Example: /image/home already exists → reject new /image/home.
	 *
	 *  Case B — Incoming ext=ON (/image/home.png):
	 *    → Duplicate ONLY if an existing entry at (path, name) has the
	 *      SAME extension (also ext=ON, same file ext).
	 *    → NOT a duplicate if extensions differ (/image/home.pdf is fine).
	 *    → NOT a duplicate if an extensionless /image/home exists —
	 *      they are DIFFERENT URLs (/image/home ≠ /image/home.png).
	 *
	 * Collision table:
	 *   Incoming \ Existing  | /image/home | /image/home.png | /image/home.pdf
	 *   ─────────────────────|─────────────|─────────────────|────────────────
	 *   /image/home (ext=OFF)| DUPLICATE   | DUPLICATE       | DUPLICATE
	 *   /image/home.png      | ALLOWED     | DUPLICATE       | ALLOWED
	 *   /image/home.pdf      | ALLOWED     | ALLOWED         | DUPLICATE
	 *
	 * @param string   $custom_path
	 * @param string   $custom_name   Already sanitized, no extension.
	 * @param bool     $include_ext   Incoming include_extension flag.
	 * @param string   $incoming_url  Incoming original_url — used to extract ext.
	 * @param int|null $exclude_id    Row to exclude (for edit operations).
	 * @return array{id:int,message:string}|null  null = no duplicate.
	 */
	private function check_extension_aware_duplicate(
		string $custom_path,
		string $custom_name,
		bool   $include_ext,
		string $incoming_url,
		?int   $exclude_id = null
	): ?array {
		if ('' === $custom_name) {
			return null;
		}

		// Get the file extension from the incoming URL.
		$incoming_ext = strtolower(pathinfo(wp_basename($incoming_url), PATHINFO_EXTENSION));

		// Fetch ALL existing entries at this (custom_path, custom_name).
		$candidates = $this->db->find_all_by_path_name($custom_path, $custom_name, $exclude_id);

		if (empty($candidates)) {
			return null;
		}

		foreach ($candidates as $existing) {
			$existing_has_ext  = (bool) $existing->include_extension;
			$existing_file_ext = strtolower(pathinfo(wp_basename($existing->original_url), PATHINFO_EXTENSION));

			$collision = false;

			if (! $include_ext) {
				// Case A: incoming is extensionless.
				// /image/home collides with ANY existing entry at this name
				// because that URL slot can only be owned by one entry.
				$collision = true;
			} else {
				// Case B: incoming has extension.
				// Only collides with an existing entry that has THE SAME extension.
				// /image/home.png ≠ /image/home.pdf  → ALLOWED
				// /image/home.png = /image/home.png  → DUPLICATE
				// /image/home (no-ext) is a DIFFERENT URL → ALLOWED
				if ($existing_has_ext && ($existing_file_ext === $incoming_ext)) {
					$collision = true;
				}
			}

			if ($collision) {
				$existing_effective = $existing_has_ext ? $existing->custom_name . '.' . $existing_file_ext : $existing->custom_name;
				$full_path = trim($existing->custom_path . '/' . $existing_effective, '/');

				return [
					'id'      => (int) $existing->id,
					'message' => sprintf(
						__('A file already exists at "%s". Do you want to replace it?', 'wp-media-manager'),
						$full_path
					),
				];
			}
		}

		return null;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Private helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Extract, sanitize, and slug-ify entry fields from $_POST.
	 *
	 * custom_name is run through sanitize_custom_name() which converts
	 * spaces to hyphens and strips unsafe URL characters.
	 *
	 * @return array<string,mixed>
	 */
	private function extract_entry_data(): array
	{
		$file_type    = sanitize_text_field($this->post_string('file_type'));
		$original_url = esc_url_raw($this->post_string('original_url'));
		$custom_name  = trim($this->post_string('custom_name'));

		// user entered no name → default to filename (without extension) from original URL.
		if ('' === $custom_name && ! empty($original_url)) {
			$custom_name = pathinfo(wp_basename($original_url), PATHINFO_FILENAME);
		} else {
			// user entered name with extension → remove extension
			$custom_name = pathinfo($custom_name, PATHINFO_FILENAME);
		}

		return [
			'attachment_id'     => absint($this->post_string('attachment_id')),
			'original_url'      => $original_url,
			'custom_name'       => Helper::sanitize_custom_name($custom_name, $file_type),
			'custom_path'       => Helper::sanitize_custom_path($this->post_string('custom_path')),
			'include_extension' => (int) (bool) $this->post_string('include_extension'),
			'file_type'         => $file_type,
		];
	}

	/**
	 * Format a DB row for JSON output.
	 *
	 * @param object $entry
	 * @return array<string,mixed>
	 */
	private function format_entry(object $entry): array
	{
		$attachment_data = $entry->attachment_id
			? $this->media->get_attachment_data((int) $entry->attachment_id)
			: null;

		// ── Thumbnail for admin card ──────────────────────────────────────────
		// ROOT CAUSE OF BROKEN THUMBNAILS:
		// wp_get_attachment_image_src($id, 'thumbnail') returns a URL like
		// /wp-content/uploads/2026/04/home-150x150.png — a WP-generated
		// resized crop. This file DOES exist in uploads but it has NO custom
		// path mapping, so when we try to build a custom URL from it we get
		// /image/home-150x150.png which doesn't exist in our DB → 404.
		//
		// CORRECT APPROACH:
		// Use wp_get_attachment_url($id) which returns the ORIGINAL full-size
		// file URL. That URL IS in our DB map and will display correctly.
		// We cap the display size with CSS (max-height on .wpmm-card__thumb).
		$thumbnail = null;

		if ($entry->attachment_id && 'image' === $entry->file_type) {
			// Always use the full-size live URL — it's always mapped.
			$live_url = wp_get_attachment_url((int) $entry->attachment_id);
			if ($live_url) {
				$thumbnail = $live_url;
			}
		}

		// Fallback: build custom URL from stored data (works even without attachment_id).
		if (! $thumbnail && 'image' === $entry->file_type) {
			$live_src = $entry->attachment_id
				? (wp_get_attachment_url((int) $entry->attachment_id) ?: $entry->original_url)
				: $entry->original_url;

			$fb_path     = Helper::build_output_path(
				$entry->custom_name,
				$entry->custom_path,
				(bool) $entry->include_extension,
				$live_src
			);
			$fb_segments = array_map('rawurlencode', explode('/', $fb_path));
			$thumbnail   = home_url('/' . implode('/', $fb_segments));
		}

		$icon = (! $thumbnail) ? Helper::get_category_icon($entry->file_type) : null;

		// ── Output path / custom URL ──────────────────────────────────────────
		// Use the live attachment URL as the extension source so that if the
		// attachment was replaced (new month folder), we still get the right ext.
		$live_original = $entry->original_url;
		if ($entry->attachment_id) {
			$live_url_check = wp_get_attachment_url((int) $entry->attachment_id);
			if ($live_url_check) {
				$live_original = $live_url_check;
			}
		}

		$output_path = Helper::build_output_path(
			$entry->custom_name,
			$entry->custom_path,
			(bool) $entry->include_extension,
			$live_original
		);

		// Build proper custom URL (encode each segment, preserve slashes).
		$encoded_segments = array_map('rawurlencode', explode('/', $output_path));
		$custom_url       = home_url('/' . implode('/', $encoded_segments));

		return [
			'id'                => (int) $entry->id,
			'attachment_id'     => (int) $entry->attachment_id,
			'original_url'      => $entry->original_url,
			'custom_name'       => $entry->custom_name,
			'custom_path'       => $entry->custom_path,
			'include_extension' => (bool) $entry->include_extension,
			'file_type'         => $entry->file_type,
			'output_path'       => $output_path,
			'custom_url'        => $custom_url,
			'thumbnail'         => $thumbnail,
			'icon'              => $icon,
			'filename'          => wp_basename($entry->original_url),
			'created_at'        => Helper::format_date($entry->created_at),
			'updated_at'        => Helper::format_date($entry->updated_at),
		];
	}

	private function post_string(string $key): string
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset($_POST[$key]) ? wp_unslash((string) $_POST[$key]) : '';
	}
}
