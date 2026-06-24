<?php
/**
 * Static helper methods shared across the plugin.
 *
 * @package WP_Media_Manager
 */

namespace WP_Media_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Helper {

	// ─────────────────────────────────────────────────────────────────────────
	// URL / path builders
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Build the resolved output path from custom settings.
	 *
	 * custom_name=connet, custom_path=image, include_extension=true  → "image/connet.pdf"
	 * custom_name=connet, custom_path=image, include_extension=false → "image/connet"
	 *
	 * @param string $custom_name
	 * @param string $custom_path
	 * @param bool   $include_extension
	 * @param string $original_url
	 * @return string
	 */
	public static function build_output_path(
		string $custom_name,
		string $custom_path,
		bool   $include_extension,
		string $original_url
	): string {
		if ( '' === $custom_name ) {
			$custom_name = pathinfo( wp_basename( $original_url ), PATHINFO_FILENAME );
		}

		$name = $custom_name;

		if ( $include_extension ) {
			$ext = strtolower( pathinfo( wp_basename( $original_url ), PATHINFO_EXTENSION ) );
			if ( $ext && ! str_ends_with( strtolower( $name ), '.' . $ext ) ) {
				$name .= '.' . $ext;
			}
		} else {
			// Strip extension if the user accidentally typed one.
			$existing_ext = pathinfo( $name, PATHINFO_EXTENSION );
			if ( $existing_ext ) {
				$name = substr( $name, 0, -( strlen( $existing_ext ) + 1 ) );
			}
		}

		$path = trim( $custom_path, '/' );
		return $path !== '' ? $path . '/' . $name : $name;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Sanitization helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Sanitize a custom file name for safe URL use.
	 *
	 * For non-PDF files: converts to a URL slug (hyphens, lowercase, ASCII).
	 * For PDF files: preserves the original name but makes it URL-safe using
	 * spaces → hyphens only, keeping capitalization and alphanumeric chars.
	 * This supports long PDF names like:
	 *   "ECP SYFOVRE Preparation For Administration Digital Resource"
	 *   → "ECP-SYFOVRE-Preparation-For-Administration-Digital-Resource"
	 * which encodes cleanly as a URL segment.
	 *
	 * @param string $raw       Raw user input (may include extension).
	 * @param string $file_type Plugin file type: 'image'|'pdf'|'video'|etc.
	 * @return string Sanitized name without extension.
	 */
	public static function sanitize_custom_name( string $raw, string $file_type = '' ): string {
		$name = trim( $raw );

		// Strip extension if the user typed one — stored/applied separately.
		$ext = pathinfo( $name, PATHINFO_EXTENSION );
		if ( $ext && strlen( $ext ) <= 5 ) {
			$name = pathinfo( $name, PATHINFO_FILENAME );
		}

		if ( 'pdf' === strtolower( $file_type ) ) {
			return self::sanitize_pdf_name( $name );
		}

		// Standard slug for all other file types.
		return self::sanitize_slug_name( $name );
	}

	/**
	 * Sanitize a name as a URL slug (for images, videos, etc.).
	 *
	 * - Converts spaces and underscores to hyphens.
	 * - Lowercases via sanitize_title().
	 * - Removes chars unsafe in URL segments.
	 *
	 * @param string $name Name without extension.
	 * @return string
	 */
	private static function sanitize_slug_name( string $name ): string {
		$name = str_replace( [ ' ', '_' ], '-', $name );
		$name = sanitize_title( $name );
		$name = (string) preg_replace( '/-+/', '-', $name );
		return trim( $name, '-' );
	}

	/**
	 * Sanitize a PDF name for URL use while preserving readability.
	 *
	 * PDFs often have long descriptive names that must remain human-readable
	 * in the URL. Instead of lowercasing and stripping to ASCII, we:
	 *   - Replace spaces with hyphens.
	 *   - Keep alphanumeric, hyphens, dots, underscores, parentheses.
	 *   - Strip truly unsafe chars (null bytes, slashes, etc.).
	 *   - Preserve capitalization.
	 *
	 * Examples:
	 *
	 *   "Q&A Guide (2025)"
	 *
	 * The resulting name is safe for rawurlencode() and displays cleanly
	 * in browser address bars.
	 *
	 * @param string $name Name without extension.
	 * @return string
	 */
	private static function sanitize_pdf_name( string $name ): string {
		$name = (string) preg_replace( '/[^A-Za-z0-9\-\._\(\)\[\]\+\s]/', '', $name );

		$name = (string) preg_replace( '/\s+/', ' ', $name );

		return trim( $name, '-. ' );
	}

	/**
	 * Sanitize a custom path for safe URL use.
	 *
	 * - Lowercases.
	 * - Converts spaces/underscores to hyphens.
	 * - Each segment is run through sanitize_title().
	 * - Strips leading/trailing slashes.
	 *
	 * Example: "My Images/2025 uploads" → "my-images/2025-uploads"
	 *
	 * @param string $raw
	 * @return string
	 */
	public static function sanitize_custom_path( string $raw ): string {
		$raw      = trim( $raw, '/' );
		$segments = explode( '/', $raw );
		$clean    = [];

		foreach ( $segments as $segment ) {
			$segment = str_replace( [ ' ', '_' ], '-', $segment );
			$segment = sanitize_title( $segment );
			$segment = (string) preg_replace( '/-+/', '-', $segment );
			$segment = trim( $segment, '-' );

			if ( $segment !== '' ) {
				$clean[] = $segment;
			}
		}

		return implode( '/', $clean );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// File type helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Get the broad file-type category from a MIME type.
	 *
	 * @param string $mime
	 * @return string  image|video|audio|pdf|document|other
	 */
	public static function get_file_category( string $mime ): string {
		if ( str_starts_with( $mime, 'image/' ) )  return 'image';
		if ( str_starts_with( $mime, 'video/' ) )  return 'video';
		if ( str_starts_with( $mime, 'audio/' ) )  return 'audio';
		if ( 'application/pdf' === $mime )          return 'pdf';

		static $doc_mimes = [
			'application/msword',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/vnd.ms-excel',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'application/vnd.ms-powerpoint',
			'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'text/plain',
			'text/csv',
		];

		return in_array( $mime, $doc_mimes, true ) ? 'document' : 'other';
	}

	/**
	 * Return a dashicon class for a file category.
	 *
	 * @param string $category
	 * @return string
	 */
	public static function get_category_icon( string $category ): string {
		return match ( $category ) {
			'image'    => 'dashicons-format-image',
			'video'    => 'dashicons-video-alt3',
			'audio'    => 'dashicons-controls-volumeon',
			'pdf'      => 'dashicons-pdf',
			'document' => 'dashicons-media-document',
			default    => 'dashicons-media-default',
		};
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Security helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Verify a nonce and die with JSON error if invalid.
	 *
	 * @param string $nonce
	 * @param string $action
	 * @return void
	 */
	public static function verify_nonce( string $nonce, string $action ): void {
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_send_json_error(
				[ 'message' => __( 'Security check failed. Please refresh and try again.', 'wp-media-manager' ) ],
				403
			);
		}
	}

	/**
	 * Check capability and die with JSON error if lacking.
	 *
	 * @param string $cap
	 * @return void
	 */
	public static function check_capability( string $cap = 'manage_options' ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error(
				[ 'message' => __( 'You do not have permission to do this.', 'wp-media-manager' ) ],
				403
			);
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Formatting
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Format a MySQL DATETIME string using site date/time format settings.
	 *
	 * @param string $datetime
	 * @return string
	 */
	public static function format_date( string $datetime ): string {
		return (string) wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			strtotime( $datetime )
		);
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// Global helper functions
// ─────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'wpmm_custom_url' ) ) {
	/**
	 * Convert any WordPress upload URL to its custom path URL.
	 *
	 * Returns the original URL unchanged if no mapping exists.
	 * Safe to call before the plugin is fully booted.
	 *
	 * Usage in themes / ACF:
	 *   echo wpmm_custom_url( get_field('image')['url'] );
	 *   echo wpmm_custom_url( get_attached_url( $id ) );
	 *
	 * @param string $upload_url
	 * @return string
	 */
	function wpmm_custom_url( string $upload_url ): string {
		if ( ! function_exists( 'wpmm' ) ) {
			return $upload_url;
		}
		try {
			return wpmm()->get_url_replacer()->get_custom_url( $upload_url );
		} catch ( \Throwable $e ) {
			return $upload_url;
		}
	}
}

if ( ! function_exists( 'wpmm_get_custom_media_url' ) ) {
	/**
	 * Alias of wpmm_custom_url() — matches the naming convention requested
	 * in the spec document.
	 *
	 * @param string $url
	 * @return string
	 */
	function wpmm_get_custom_media_url( string $url ): string {
		return wpmm_custom_url( $url );
	}
}
