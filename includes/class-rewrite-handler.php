<?php

/**
 * Custom URL rewrite handling and secure file serving.
 *
 * Uses parse_request (not rewrite rules) to intercept matching paths
 * so normal WP pages/posts/archives are NEVER affected.
 *
 * Also handles favicon.ico by hooking do_favicon before parse_request.
 *
 * @package WP_Media_Manager
 */

namespace WP_Media_Manager;

if (! defined('ABSPATH')) {
	exit;
}

class Rewrite_Handler
{

	/** File categories served inline (vs forced download). */
	private const INLINE_TYPES = ['image', 'pdf', 'video', 'audio'];

	/**
	 * Paths that are ALWAYS WordPress-owned — never intercept.
	 * NOTE: favicon.ico is intentionally NOT in this list so we can serve it.
	 *
	 * @var array<string>
	 */
	private const WP_CORE_PREFIXES = [
		'wp-admin',
		'wp-includes',
		'wp-content',
		'wp-login.php',
		'wp-cron.php',
		'wp-json',
		'xmlrpc.php',
		'feed',
		'sitemap',
		'robots.txt',
		'index.php',
	];

	/** @var Database */
	private Database $db;

	public function __construct(Database $db)
	{
		$this->db = $db;
	}

	public function register(): void
	{
		// Priority 1 on parse_request — fires before WP builds main query.
		add_action('parse_request', [$this, 'intercept_request'], 1);

		// Favicon: hook do_favicon at priority 1 to serve our mapped favicon
		// BEFORE WordPress sends its default logo redirect.
		add_action('do_favicon', [$this, 'handle_favicon'], 1);

		// Uploads redirect: 301-redirect /wp-content/uploads/... URLs to the
		// corresponding custom path URL when a mapping exists.
		// Uses template_redirect so it only fires on real front-end requests
		// after WordPress has parsed the URL (not admin, not AJAX, not REST).
		add_action('template_redirect', [$this, 'maybe_redirect_uploads_url'], 1);
	}

	/**
	 * Kept for Activator compatibility — routing is done via parse_request,
	 * not rewrite rules, so this is intentionally empty.
	 *
	 * @return void
	 */
	public static function register_rules(): void {}

	// ─────────────────────────────────────────────────────────────────────────
	// Uploads URL → custom URL redirect
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * 301-redirect /wp-content/uploads/… URLs to their custom path URLs.
	 *
	 * WHEN IT FIRES:
	 *   template_redirect priority 1 — after WP has fully parsed the request
	 *   but before any template output. Only fires on front-end page requests
	 *   (not admin, not AJAX, not REST API, not CLI).
	 *
	 * LOOP PREVENTION:
	 *   We only redirect if the REQUEST_URI starts with the uploads path.
	 *   The target URL is a custom path (e.g. /image/home.png), not an
	 *   uploads URL, so there is no possibility of an infinite redirect.
	 *
	 * SECURITY:
	 *   We validate the mapping exists in the DB before redirecting.
	 *   We only redirect to home_url() paths (same-site).
	 *
	 * @return void
	 */
	public function maybe_redirect_uploads_url(): void
	{
		// Skip non-front-end contexts.
		if (is_admin() || wp_doing_ajax()) {
			return;
		}
		if (defined('REST_REQUEST') && REST_REQUEST) {
			return;
		}
		if (defined('DOING_CRON') && DOING_CRON) {
			return;
		}

		// Use wp_unslash only — sanitize_text_field would strip encoded chars
		// like %20 from the URI before we can decode them properly.
		$raw_uri = wp_unslash($_SERVER['REQUEST_URI'] ?? '');
		if (empty($raw_uri)) {
			return;
		}

		// Strip query string, then decode percent-encoding (%20 → space, etc.).
		$request_path = rawurldecode(strtok($raw_uri, '?'));

		// Build the uploads path prefix from wp_upload_dir.
		$upload_dir          = wp_upload_dir();
		$uploads_base_url    = $upload_dir['baseurl']; // e.g. https://site.com/wp-content/uploads
		$site_url            = get_site_url();

		// Get the path portion only: /wp-content/uploads
		$uploads_path_prefix = rtrim(
			(string) wp_parse_url($uploads_base_url, PHP_URL_PATH),
			'/'
		);

		// Only proceed if this request is for a file inside uploads.
		if (empty($uploads_path_prefix)) {
			return;
		}
		if (! str_starts_with($request_path, $uploads_path_prefix . '/')) {
			return;
		}

		// Build the full URL variants to try in the DB.
		// We try both http and https because the stored original_url might
		// use a different protocol than the current request.
		$host     = wp_unslash($_SERVER['HTTP_HOST'] ?? '');
		$is_ssl   = is_ssl() || (isset($_SERVER['HTTPS']) && 'on' === strtolower($_SERVER['HTTPS'] ?? ''));
		$proto    = $is_ssl ? 'https' : 'http';
		$alt_proto = $is_ssl ? 'http' : 'https';

		$full_url     = $proto . '://' . $host . $request_path;
		$full_url_alt = $alt_proto . '://' . $host . $request_path;

		// Attempt DB lookup — try current protocol first, then alternate.
		$entry = $this->db->find_by_original_url($full_url)
			?? $this->db->find_by_original_url($full_url_alt)
			?? $this->db->find_by_original_url_path($request_path, $upload_dir);

		if (! $entry) {
			return; // No mapping — serve normally.
		}

		// Build the target custom URL using the live attachment URL.
		$live_url = $entry->attachment_id
			? (wp_get_attachment_url((int) $entry->attachment_id) ?: $entry->original_url)
			: $entry->original_url;

		$output_path = Helper::build_output_path(
			$entry->custom_name,
			$entry->custom_path,
			(bool) $entry->include_extension,
			$live_url
		);

		if (empty($output_path)) {
			return;
		}

		// Encode each path segment individually (spaces → %20, slashes preserved).
		$segments   = array_map('rawurlencode', explode('/', $output_path));
		$custom_url = home_url('/' . implode('/', $segments));

		// Infinite loop guard: never redirect to the same URL.
		if (rtrim($custom_url, '/') === rtrim($full_url, '/')) {
			return;
		}

		// 301 permanent redirect — establishes the custom URL as canonical.
		wp_redirect($custom_url, 301);
		exit;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Favicon handler
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Intercept WordPress's do_favicon action.
	 *
	 * WordPress fires do_favicon when REQUEST_URI ends in /favicon.ico.
	 * By hooking at priority 1 we run before WP's own handler which would
	 * redirect to the default logo PNG.
	 *
	 * @return void
	 */
	public function handle_favicon(): void
	{
		$entry = $this->db->find_by_request_path('favicon.ico');

		if (! $entry) {
			return; // No mapping — let WP handle it.
		}

		$file_path = $this->resolve_file_path($entry);

		if (! $file_path) {
			return;
		}

		// Serve with correct ICO content-type.
		status_header(200);
		header('Content-Type: image/x-icon');
		header('Content-Length: ' . (int) @filesize($file_path));
		header('Cache-Control: public, max-age=86400');
		header('X-Content-Type-Options: nosniff');

		if (ob_get_level()) {
			ob_end_clean();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile($file_path);
		exit;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Main request interception
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Fired on parse_request priority 1.
	 *
	 * Reads REQUEST_URI directly → rawurldecode() → DB lookup → serve or pass.
	 *
	 * @param \WP $wp (unused, required by hook signature).
	 * @return void
	 */
	private static ?array $redirect_cache = null;
	public function intercept_request(\WP $wp): void
	{
		// Only intercept GET / HEAD.
		$method = strtoupper(
			sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? 'GET'))
		);

		if (! in_array($method, ['GET', 'HEAD'], true)) {
			return;
		}

		$request_uri = wp_unslash($_SERVER['REQUEST_URI'] ?? '');
		$http_host   = wp_unslash($_SERVER['HTTP_HOST'] ?? '');

		if (empty($request_uri)) {
			return;
		}

		// 1. Separates the current full request URL (Domain + Path) and the path only.
		$is_ssl       = is_ssl() || (isset($_SERVER['HTTPS']) && 'on' === strtolower($_SERVER['HTTPS']));
		$current_proto = $is_ssl ? 'https://' : 'http://';

		$full_request_url = $current_proto . $http_host . $request_uri;
		$clean_full_url   = rawurldecode(strtok($full_request_url, '?'));

		$clean_path = rawurldecode(strtok($request_uri, '?'));

		$site_path = parse_url(home_url('/'), PHP_URL_PATH);
		if (! empty($site_path) && '/' !== $site_path) {
			$site_path = trim($site_path, '/');
			$clean_path = ltrim($clean_path, '/');
			if (str_starts_with($clean_path, $site_path)) {
				$clean_path = substr($clean_path, strlen($site_path));
			}
		}

		$path = $this->normalize_path($clean_path);
		if (null !== $path) {
			$path = trim($path, '/');
		}

		// 2. Multi-Domain Redirection Lookup
		global $wpdb;
		$table_name = $wpdb->prefix . WPMM_REDIRECT_TABLE_NAME;

		// Checks whether it exists in the database as a full domain URL or just as a path.
		if (null === self::$redirect_cache) {
			$table_name = $wpdb->prefix . WPMM_REDIRECT_TABLE_NAME;
			$rules = $wpdb->get_results("SELECT * FROM {$table_name} WHERE is_active = 1");
			self::$redirect_cache = $rules ? $rules : [];
		}

		$redirect_rule = null;
		foreach (self::$redirect_cache as $rule) {
			if ($rule->source_path === $path || $rule->source_path === $clean_full_url || $rule->source_path === rtrim($clean_full_url, '/')) {
				$redirect_rule = $rule;
				break;
			}
		}

		if ($redirect_rule) {
			// Increases the hit count.
			$wpdb->query($wpdb->prepare("UPDATE {$table_name} SET hits_count = hits_count + 1 WHERE id = %d", $redirect_rule->id));

			$type = (int) $redirect_rule->redirect_type;

			if (404 === $type) {
				global $wp_query;
				$wp_query->set_404();
				status_header(404);
				nocache_headers();
				include(get_404_template());
				exit;
			}

			if (in_array($type, [301, 302, 307, 308], true)) {
				$target = trim($redirect_rule->target_url);

				// If the target URL does not contain a domain (http/https), 
				// the current site's domain will be appended.
				if (! empty($target) && ! str_starts_with($target, 'http://') && ! str_starts_with($target, 'https://')) {
					// Adds a slash (/) at the beginning of the path if it is missing.
					$target_path = '/' . ltrim($target, '/');
					$target      = home_url($target_path);
				} else {
					$target = esc_url_raw($target);
				}

				// Now redirects accurately to the complete URL.
				wp_redirect($target, $type);
				exit;
			}
		}

		if (null === $path) {
			return;
		}

		// The old media lookup logic will continue as is...
		$entry = $this->db->find_by_request_path($path);

		if (! $entry) {
			return;
		}

		$this->dispatch($entry);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Dispatch
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Validate the entry and stream the file.
	 *
	 * @param object $entry DB row.
	 * @return never
	 */
	private function dispatch(object $entry): never
	{
		if ($entry->attachment_id) {
			$post_type = get_post_type((int) $entry->attachment_id);
			if (false === $post_type || 'attachment' !== $post_type) {
				status_header(410);
				exit;
			}
		}

		$file_path = $this->resolve_file_path($entry);

		if (! $file_path) {
			status_header(404);
			nocache_headers();
			exit;
		}

		$this->serve_file($file_path, (string) $entry->file_type);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// File serving
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Stream a file to the browser.
	 *
	 * @param string $file_path Absolute FS path.
	 * @param string $file_type Plugin category.
	 * @return never
	 */
	private function serve_file(string $file_path, string $file_type): never
	{
		$mime = $this->detect_mime($file_path);

		if (! $mime) {
			status_header(415);
			exit;
		}

		// Security: must be inside wp-content/uploads.
		$upload_base = wp_normalize_path(trailingslashit(wp_upload_dir()['basedir']));
		$real        = wp_normalize_path(realpath($file_path) ?: $file_path);

		if (! str_starts_with($real, $upload_base)) {
			status_header(403);
			exit;
		}

		$file_size   = (int) @filesize($file_path);
		$disposition = in_array($file_type, self::INLINE_TYPES, true) ? 'inline' : 'attachment';

		if (! empty($_SERVER['HTTP_RANGE'])) {
			$this->serve_range($file_path, $file_size, $mime);
		}

		status_header(200);
		header('Content-Type: ' . $mime);
		header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode(basename($file_path)) . '"');
		header('Content-Length: ' . $file_size);
		header('Accept-Ranges: bytes');

		// 🆕 Aggressive Browser Cache Layers to eliminate 2-second streaming roundtrips
		header('Cache-Control: public, max-age=31536000, stale-while-revalidate=604800');
		header('Pragma: cache');
		header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
		header('X-Content-Type-Options: nosniff');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) filemtime($file_path)) . ' GMT');

		if (ob_get_level()) {
			ob_end_clean();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile($file_path);
		exit;
	}

	/**
	 * Serve a partial byte-range response (RFC 7233).
	 *
	 * @param string $file_path
	 * @param int    $file_size
	 * @param string $mime
	 * @return never
	 */
	private function serve_range(string $file_path, int $file_size, string $mime): never
	{
		$range = sanitize_text_field(wp_unslash($_SERVER['HTTP_RANGE'] ?? ''));

		if (! preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m)) {
			status_header(416);
			header('Content-Range: bytes */' . $file_size);
			exit;
		}

		$start = $m[1] !== '' ? (int) $m[1] : 0;
		$end   = $m[2] !== '' ? (int) $m[2] : $file_size - 1;
		$end   = min($end, $file_size - 1);

		if ($start > $end || $start >= $file_size) {
			status_header(416);
			header('Content-Range: bytes */' . $file_size);
			exit;
		}

		$length = $end - $start + 1;

		status_header(206);
		header('Cache-Control: public, max-age=31536000, stale-while-revalidate=604800');
		header('Content-Type: ' . $mime);
		header("Content-Range: bytes {$start}-{$end}/{$file_size}");
		header('Content-Length: ' . $length);
		header('Accept-Ranges: bytes');

		if (ob_get_level()) {
			ob_end_clean();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$fp = fopen($file_path, 'rb');
		if (! $fp) {
			status_header(500);
			exit;
		}

		fseek($fp, $start);
		$remaining = $length;

		while (! feof($fp) && $remaining > 0) {
			$chunk = min(8192, $remaining);
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			$buf   = fread($fp, $chunk);
			if (false === $buf) {
				break;
			}
			$remaining -= strlen($buf);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $buf;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose($fp);
		exit;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Resolve the real filesystem path for an entry.
	 *
	 * @param object $entry
	 * @return string|null
	 */
	private function resolve_file_path(object $entry): ?string
	{
		// Prefer WP attachment path (handles moved/renamed files correctly).
		if ($entry->attachment_id) {
			$path = get_attached_file((int) $entry->attachment_id);
			if ($path && file_exists($path)) {
				return $path;
			}
		}

		// Fallback: derive from stored original_url.
		$upload_dir = wp_upload_dir();
		$base_url   = trailingslashit($upload_dir['baseurl']);
		$base_dir   = trailingslashit($upload_dir['basedir']);
		$url        = strtok((string) $entry->original_url, '?');

		if (! str_starts_with($url, $base_url)) {
			return null;
		}

		$relative = substr($url, strlen($base_url));
		$path     = $base_dir . rawurldecode($relative);

		return file_exists($path) ? $path : null;
	}

	/**
	 * Detect MIME type via finfo → extension lookup fallback.
	 *
	 * @param string $file_path
	 * @return string|null
	 */
	private function detect_mime(string $file_path): ?string
	{
		if (function_exists('finfo_open')) {
			$fi = finfo_open(FILEINFO_MIME_TYPE);
			if ($fi) {
				$mime = finfo_file($fi, $file_path);
				finfo_close($fi);
				if ($mime && 'application/octet-stream' !== $mime) {
					return $mime;
				}
			}
		}

		// Extension-based fallback.
		$ext   = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
		$mimes = wp_get_mime_types();

		// Add .ico explicitly — WP doesn't include it by default.
		$mimes['ico'] = 'image/x-icon';

		foreach ($mimes as $exts => $mime) {
			if (in_array($ext, explode('|', $exts), true)) {
				return $mime;
			}
		}

		return null;
	}

	/**
	 * Sanitise and validate an inbound request path.
	 *
	 * Returns null for paths that must not be intercepted.
	 *
	 * @param string $raw Decoded path from REQUEST_URI.
	 * @return string|null
	 */
	private function normalize_path(string $raw): ?string
	{
		$path = str_replace("\0", '', $raw);

		if (str_contains($path, '..')) {
			return null;
		}

		$path = ltrim($path, '/');
		$path = (string) preg_replace('#/+#', '/', $path);

		if ('' === $path) {
			return null;
		}

		// Bypasses the wp-content restriction if they are old theme PDF files.
		$current_theme = get_stylesheet(); 
		if (str_starts_with($path, 'wp-content/themes/' . $current_theme . '/pdf/')) {
			return $path;
		}

		// Continues to block other common WordPress core paths as before.
		foreach (self::WP_CORE_PREFIXES as $prefix) {
			if (str_starts_with($path, $prefix)) {
				return null;
			}
		}

		return $path;
	}
}
