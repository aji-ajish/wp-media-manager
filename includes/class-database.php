<?php

/**
 * Database management — schema, CRUD, duplicate detection, URL lookup.
 *
 * IMPORTANT — dbDelta() SQL formatting rules (MySQL/WordPress specific):
 *  - There must be two spaces between PRIMARY KEY and the opening paren.
 *  - Every column definition and KEY line must start with exactly two spaces.
 *  - Column types must be uppercase.
 *  - No trailing comma after the last column / key line.
 *  - The opening CREATE TABLE line and closing ) ENGINE= line have NO
 *    leading spaces.
 *
 * @package WP_Media_Manager
 */

namespace WP_Media_Manager;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class Database
 */
class Database
{

	/** @var string Full prefixed table name. */
	private string $table;

	public function __construct()
	{
		global $wpdb;
		$this->table = $wpdb->prefix . WPMM_TABLE_NAME;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Schema
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Create or upgrade the plugin table using dbDelta.
	 *
	 * Idempotent — safe to call on every activation or init. dbDelta only
	 * modifies the table if the schema has changed.
	 *
	 * CRITICAL FORMATTING NOTE:
	 * The $sql string below must NOT be reformatted by an IDE or code sniffer.
	 * dbDelta() is whitespace-sensitive and requires the exact spacing shown.
	 *
	 * @return bool True if table exists after the call.
	 */
	public static function create_tables(): bool
	{
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$media_table     = $wpdb->prefix . WPMM_TABLE_NAME;
		$redirect_table  = $wpdb->prefix . WPMM_REDIRECT_TABLE_NAME;
		/*
		 * dbDelta formatting rules — DO NOT auto-format this block:
		 *  - Two spaces before every column / key line.
		 *  - Two spaces between PRIMARY KEY and opening paren.
		 *  - NO semicolon at the end — dbDelta silently fails with one.
		 *  - NO "ON UPDATE CURRENT_TIMESTAMP" — only one CURRENT_TIMESTAMP
		 *    default per table is supported on MySQL 5.7 / older MariaDB
		 *    (common on shared hosts). updated_at is set manually in code.
		 *  - UNIQUE KEY prefix lengths ≤ 100 to stay inside the 767-byte
		 *    InnoDB limit for utf8mb4 (4 bytes × 100 chars × 2 cols = 800 — but
		 *    WP's InnoDB row format is DYNAMIC which allows larger; 100 is safe
		 *    across all MySQL/MariaDB versions and row formats).
		 */
		// phpcs:disable
		$sql_media = "CREATE TABLE {$media_table} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		attachment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		original_url TEXT NOT NULL,
		original_url_hash VARCHAR(32) NOT NULL DEFAULT '',
		custom_name VARCHAR(255) NOT NULL DEFAULT '',
		custom_path VARCHAR(500) NOT NULL DEFAULT '',
		include_extension TINYINT(1) NOT NULL DEFAULT 1,
		file_type VARCHAR(100) NOT NULL DEFAULT '',
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY idx_attachment_id (attachment_id),
		UNIQUE KEY unique_path_name (custom_path(100),custom_name(100),include_extension,original_url_hash)
		) {$charset_collate};";

		$sql_redirect = "CREATE TABLE {$redirect_table} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		source_path VARCHAR(500) NOT NULL DEFAULT '',
		target_url TEXT NOT NULL,
		redirect_type INT(3) NOT NULL DEFAULT 301,
		is_active TINYINT(1) NOT NULL DEFAULT 1,
		hits_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		UNIQUE KEY unique_source_path (source_path(190))
		) {$charset_collate};";
		// phpcs:enable

		if (! function_exists('dbDelta')) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$result_media    = dbDelta($sql_media);
		$result_redirect = dbDelta($sql_redirect);

		if (! empty($result_media) || ! empty($result_redirect)) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log('[WP Media Manager] dbDelta triggered for media and redirect tables.');
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$media_exists    = (bool) $wpdb->get_var("SHOW TABLES LIKE '{$media_table}'");
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$redirect_exists = (bool) $wpdb->get_var("SHOW TABLES LIKE '{$redirect_table}'");

		$all_exist = $media_exists && $redirect_exists;

		if (! $all_exist) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log("[WP Media Manager] create_tables() FAILED. Last DB error: {$wpdb->last_error}");
		}

		return $all_exist;
	}

	/**
	 * Drop the plugin table — used by uninstall.php only.
	 *
	 * @return void
	 */
	public static function drop_tables(): void
	{
		global $wpdb;
		$table = $wpdb->prefix . WPMM_TABLE_NAME;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query("DROP TABLE IF EXISTS {$table}");
		$wpdb->query("DROP TABLE IF EXISTS " . $wpdb->prefix . WPMM_REDIRECT_TABLE_NAME);
	}

	/**
	 * Ensure the table exists; create it if missing.
	 *
	 * Called before every write so a missing table never causes a silent
	 * SQL failure. Uses a static flag to avoid SHOW TABLES on every call.
	 *
	 * @return bool
	 */
	public function ensure_table(): bool
	{
		static $verified = false;

		if ($verified) {
			return true;
		}

		global $wpdb;
		$table = $wpdb->prefix . WPMM_TABLE_NAME;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'")) {
			$verified = true;
			return true;
		}

		// Table missing — create it now.
		$created = self::create_tables();

		if ($created) {
			$verified = true;
		} else {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log('[WP Media Manager] ensure_table(): table still missing after create attempt.');
		}

		return $created;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// CRUD
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Insert a new entry.
	 *
	 * @param array<string,mixed> $data
	 * @return int|false Inserted ID, or false on failure.
	 */
	public function insert(array $data): int|false
	{
		global $wpdb;

		if (! $this->ensure_table()) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log('[WP Media Manager] insert() aborted — table unavailable.');
			return false;
		}

		$sanitized = $this->sanitize_entry($data);
		$result    = $wpdb->insert(
			$this->table,
			$sanitized,
			$this->get_format($sanitized)
		);

		if (false === $result) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log('[WP Media Manager] insert() DB error: ' . $wpdb->last_error);
			return false;
		}
		delete_transient('wpmm_url_lookup_map');
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing entry.
	 *
	 * Sets updated_at manually because the schema removed ON UPDATE
	 * CURRENT_TIMESTAMP for MySQL 5.7 / MariaDB compatibility.
	 *
	 * @param int                 $id
	 * @param array<string,mixed> $data
	 * @return bool
	 */
	public function update(int $id, array $data): bool
	{
		global $wpdb;

		if (! $this->ensure_table()) {
			return false;
		}

		$sanitized               = $this->sanitize_entry($data);
		$sanitized['updated_at'] = current_time('mysql', true);

		$result = $wpdb->update(
			$this->table,
			$sanitized,
			['id' => $id],
			$this->get_format($sanitized),
			['%d']
		);

		if (false === $result) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log('[WP Media Manager] update() DB error: ' . $wpdb->last_error);
		}
		delete_transient('wpmm_url_lookup_map');
		return $result !== false;
	}

	/**
	 * Overwrite all fields of an existing row (duplicate-replace flow).
	 *
	 * We set updated_at manually here because the schema no longer uses
	 * ON UPDATE CURRENT_TIMESTAMP (removed for MySQL 5.7 compatibility).
	 *
	 * @param int                 $id
	 * @param array<string,mixed> $data
	 * @return bool
	 */
	public function replace_entry(int $id, array $data): bool
	{
		global $wpdb;

		if (! $this->ensure_table()) {
			return false;
		}

		$sanitized               = $this->sanitize_entry($data);
		$sanitized['updated_at'] = current_time('mysql', true);

		$result = $wpdb->update(
			$this->table,
			$sanitized,
			['id' => $id],
			$this->get_format($sanitized),
			['%d']
		);
		delete_transient('wpmm_url_lookup_map');
		return $result !== false;
	}

	/**
	 * Delete an entry by ID.
	 *
	 * @param int $id
	 * @return bool
	 */
	public function delete(int $id): bool
	{
		global $wpdb;

		$result = $wpdb->delete(
			$this->table,
			['id' => $id],
			['%d']
		);
		delete_transient('wpmm_url_lookup_map');
		return $result !== false;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Read / lookup
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Get a single row by ID.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public function get(int $id): ?object
	{
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
				$id
			)
		);
	}

	/**
	 * Find a duplicate by (custom_path, custom_name), excluding one row.
	 *
	 * Returns the FIRST matching row. For extension-aware duplicate checking
	 * use find_all_by_path_name() which returns all rows.
	 *
	 * @param string   $custom_path
	 * @param string   $custom_name
	 * @param int|null $exclude_id
	 * @return object|null
	 */
	public function find_duplicate(
		string $custom_path,
		string $custom_name,
		?int   $exclude_id = null
	): ?object {
		global $wpdb;

		$path = sanitize_text_field($custom_path);
		$name = sanitize_text_field($custom_name);

		if ($exclude_id) {
			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->table}
					 WHERE custom_path = %s AND custom_name = %s AND id != %d
					 LIMIT 1",
					$path,
					$name,
					$exclude_id
				)
			);
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				 WHERE custom_path = %s AND custom_name = %s
				 LIMIT 1",
				$path,
				$name
			)
		);
	}

	/**
	 * Return ALL entries with the same (custom_path, custom_name), optionally
	 * excluding one row (for edit operations).
	 *
	 * Used by the extension-aware duplicate check which needs to compare the
	 * INCOMING effective URL against every EXISTING entry's effective URL —
	 * because two entries can share the same custom_name if their extensions
	 * differ (e.g. home.png ≠ home.pdf) but a third entry with the same
	 * name+ext would be a real duplicate.
	 *
	 * @param string   $custom_path
	 * @param string   $custom_name
	 * @param int|null $exclude_id
	 * @return array<object>
	 */
	public function find_all_by_path_name(
		string $custom_path,
		string $custom_name,
		?int   $exclude_id = null
	): array {
		global $wpdb;

		$path = sanitize_text_field($custom_path);
		$name = sanitize_text_field($custom_name);

		if ($exclude_id) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table}
					 WHERE custom_path = %s AND custom_name = %s AND id != %d",
					$path,
					$name,
					$exclude_id
				)
			) ?: [];
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				 WHERE custom_path = %s AND custom_name = %s",
				$path,
				$name
			)
		) ?: [];
	}

	/**
	 * Resolve a URL request path to a DB entry.
	 *
	 * Tries name-without-extension first (covers both include_extension modes),
	 * then falls back to exact name match (covers entries stored with extension).
	 *
	 * @param string $request_path  Decoded path, e.g. "image/connet-page" or "pdf/report.pdf"
	 * @return object|null
	 */
	public function find_by_request_path(string $request_path): ?object
	{
		global $wpdb;

		$request_path = ltrim($request_path, '/');
		$last_slash   = strrpos($request_path, '/');

		// Split the path and file name
		if ($last_slash !== false) {
			$dir  = substr($request_path, 0, $last_slash);
			$file = substr($request_path, $last_slash + 1);
		} else {
			$dir  = '';
			$file = $request_path;
		}

		$dot         = strrpos($file, '.');
		$file_no_ext = $dot !== false ? substr($file, 0, $dot) : $file;
		$request_ext = $dot !== false ? strtolower(substr($file, $dot + 1)) : '';

		// 1. Match by name-without-extension (works for both URL modes).
		// 2. Exact name match (entry stored WITH extension in custom_name).
		if (! empty($request_ext)) {
			$like_pattern = '%' . $wpdb->esc_like('.' . $request_ext);

			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->table}
                 WHERE custom_path = %s 
                 AND custom_name = %s 
                 AND include_extension = 1
                 AND original_url LIKE %s
                 LIMIT 1",
					$dir,
					$file_no_ext,
					$like_pattern
				)
			);

			if ($row) {
				return $row;
			}
		}

		// 1. Match by name-without-extension (works for both URL modes).
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				 WHERE custom_path = %s AND custom_name = %s
				 LIMIT 1",
				$dir,
				$file_no_ext
			)
		);

		if ($row) {
			return $row;
		}

		// 2. Exact name match (entry stored WITH extension in custom_name).
		if ($file !== $file_no_ext) {
			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->table}
					 WHERE custom_path = %s AND custom_name = %s
					 LIMIT 1",
					$dir,
					$file
				)
			);
		}

		return null;
	}

	/**
	 * Look up an entry by its original WordPress upload URL.
	 *
	 * Tries an exact match against the stored original_url.
	 *
	 * @param string $original_url
	 * @return object|null
	 */
	public function find_by_original_url(string $original_url): ?object
	{
		global $wpdb;

		$url = rawurldecode(strtok($original_url, '?'));

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				 WHERE original_url = %s
				 LIMIT 1",
				$url
			)
		);
	}

	/**
	 * Look up an entry by the path portion of an uploads URL.
	 *
	 * Used by the uploads redirect when the stored URL might have a different
	 * protocol (http vs https) or domain variant than the request.
	 * Matches by comparing the relative path inside wp-content/uploads/.
	 *
	 * @param string               $request_path  e.g. "/wp-content/uploads/2026/04/home.png"
	 * @param array<string,string> $upload_dir    From wp_upload_dir().
	 * @return object|null
	 */
	public function find_by_original_url_path(string $request_path, array $upload_dir): ?object
	{
		global $wpdb;

		// Extract the relative path after the uploads base dir.
		$base_url = trailingslashit($upload_dir['baseurl']);
		$base_dir = trailingslashit($upload_dir['basedir']);

		// Build all plausible stored URL variants (http + https, www + non-www).
		$protocols = ['http', 'https'];
		$file_rel  = ltrim($request_path, '/');

		// Build the URL with just the site host portion.
		$site_host = wp_parse_url($base_url, PHP_URL_HOST) ?? '';

		$candidates = [];
		foreach ($protocols as $proto) {
			// Standard URL.
			$candidates[] = $proto . '://' . $site_host . '/' . $file_rel;
			// Also try normalising double slashes.
			$candidates[] = $proto . '://' . $site_host . '/' . ltrim($file_rel, '/');
		}

		// Remove duplicates.
		$candidates = array_unique($candidates);

		foreach ($candidates as $candidate) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->table}
					 WHERE original_url = %s
					 LIMIT 1",
					$candidate
				)
			);
			if ($row) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Find all entries that share an attachment_id.
	 *
	 * @param int $attachment_id
	 * @return array<object>
	 */
	public function find_by_attachment_id(int $attachment_id): array
	{
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE attachment_id = %d",
				$attachment_id
			)
		) ?: [];
	}

	/**
	 * Paginated list with optional search.
	 *
	 * @param array<string,mixed> $args
	 * @return array<object>
	 */
	public function get_all(array $args = []): array
	{
		global $wpdb;

		$args    = wp_parse_args($args, [
			'search'   => '',
			'per_page' => 20,
			'page'     => 1,
			'orderby'  => 'created_at',
			'order'    => 'DESC',
		]);

		$offset  = ((int) $args['page'] - 1) * (int) $args['per_page'];
		$orderby = in_array($args['orderby'], ['id', 'custom_name', 'file_type', 'created_at'], true)
			? $args['orderby'] : 'created_at';
		$order   = 'ASC' === strtoupper((string) $args['order']) ? 'ASC' : 'DESC';
		$where   = '1=1';
		$values  = [];

		if (! empty($args['search'])) {
			$like    = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
			$where  .= ' AND (custom_name LIKE %s OR custom_path LIKE %s OR file_type LIKE %s)';
			$values  = [$like, $like, $like];
		}

		if ((int) $args['per_page'] === -1) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table} WHERE {$where} ORDER BY {$orderby} {$order}",
					$values
				)
			) ?: [];
		}

		$offset  = ((int) $args['page'] - 1) * (int) $args['per_page'];

		$values[] = (int) $args['per_page'];
		$values[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
				$values
			)
		) ?: [];
	}

	/**
	 * Count entries with optional search.
	 *
	 * @param string $search
	 * @return int
	 */
	public function count(string $search = ''): int
	{
		global $wpdb;

		if (! empty($search)) {
			$like = '%' . $wpdb->esc_like(sanitize_text_field($search)) . '%';
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->table}
					 WHERE custom_name LIKE %s OR custom_path LIKE %s OR file_type LIKE %s",
					$like,
					$like,
					$like
				)
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Private helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function sanitize_entry(array $data): array
	{
		$clean = [];

		if (array_key_exists('attachment_id', $data))     $clean['attachment_id']     = absint($data['attachment_id']);

		if (array_key_exists('original_url', $data)) {
			$url = esc_url_raw((string) $data['original_url']);
			$clean['original_url']      = $url;
			$clean['original_url_hash'] = md5(rawurldecode(strtok($url, '?')));
		}

		if (array_key_exists('custom_name', $data))       $clean['custom_name']       = sanitize_text_field((string) $data['custom_name']);
		if (array_key_exists('custom_path', $data))       $clean['custom_path']       = sanitize_text_field((string) $data['custom_path']);
		if (array_key_exists('include_extension', $data)) $clean['include_extension'] = (int) (bool) $data['include_extension'];
		if (array_key_exists('file_type', $data))         $clean['file_type']         = sanitize_text_field((string) $data['file_type']);

		return $clean;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string>
	 */
	private function get_format(array $data): array
	{
		$map = [
			'attachment_id'     => '%d',
			'original_url'      => '%s',
			'original_url_hash' => '%s',
			'custom_name'       => '%s',
			'custom_path'       => '%s',
			'include_extension' => '%d',
			'file_type'         => '%s',
			'updated_at'        => '%s',
		];

		$formats = [];
		foreach ($data as $key => $v) {
			$formats[] = $map[$key] ?? '%s';
		}
		return $formats;
	}
}
