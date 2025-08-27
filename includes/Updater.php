<?php
/**
 * Updater that manages ONLY the Newfold-managed block via markers,
 * leaving native WordPress rules and any other content untouched.
 *
 * Adds an in-block header and checksum, and skips writes if unchanged.
 *
 * @package NewfoldLabs\WP\Module\Htaccess
 */

namespace NewfoldLabs\WP\Module\Htaccess;

class Updater {

	/**
	 * Marker label used to bracket our managed section.
	 *
	 * @var string
	 */
	protected $marker = 'NFD Htaccess';

	/**
	 * Apply (insert/replace) our managed block in .htaccess.
	 *
	 * The final block will look like:
	 *
	 *   # BEGIN NFD Htaccess
	 *   # Managed by Newfold Htaccess Manager vX.Y (example.com)
	 *   # STATE sha256: <body-hash> applied: 2025-08-27T15:03:34Z
	 *   <fragment-1>
	 *   <blank>
	 *   <fragment-2>
	 *   ...
	 *   # END NFD Htaccess
	 *
	 * @since 1.0.0
	 *
	 * @param string $body    Concatenated NFD fragments (no trailing newline required).
	 * @param string $host    Host label for header (e.g., example.com).
	 * @param string $version Module version string for header.
	 * @return bool True on success, false on failure or no-op when unchanged.
	 */
	public function apply_managed_block( $body, $host, $version ) {
		$path = $this->get_htaccess_path();
		if ( '' === $path ) {
			return false;
		}

		$this->ensure_wp_file_helpers();

		// Normalize incoming body and compute checksum.
		$body_norm   = $this->normalize( (string) $body );
		$body_hash   = hash( 'sha256', $body_norm );
		$applied_iso = gmdate( 'Y-m-d\TH:i:s\Z' );

		// Build the lines we intend to write inside our markers.
		$lines = $this->build_block_lines( $body_norm, $host, $version, $body_hash, $applied_iso );

		// If block already exists and checksum matches, no-op.
		$current_lines = $this->get_current_block_lines( $path );
		$current_hash  = $this->extract_hash_from_lines( $current_lines );
		if ( '' !== $current_hash && $current_hash === $body_hash ) {
			return true; // no change
		}

		// Insert/replace the block between our markers (preserves rest of file).
		return (bool) insert_with_markers( $path, $this->marker, $lines ); // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	}

	/**
	 * Build the full set of lines for the NFD block (header + body).
	 *
	 * @param string $body_norm  Normalized body text.
	 * @param string $host       Host label.
	 * @param string $version    Version string.
	 * @param string $body_hash  sha256 of body_norm.
	 * @param string $applied_iso UTC timestamp.
	 * @return array
	 */
	protected function build_block_lines( $body_norm, $host, $version, $body_hash, $applied_iso ) {
		$header = array(
			'# Managed by Newfold Htaccess Manager v' . $version . ' (' . $host . ')',
			'# STATE sha256: ' . $body_hash . ' applied: ' . $applied_iso,
		);

		$body_lines = ( '' === $body_norm ) ? array() : explode( "\n", $body_norm );

		// Separate header and body with a blank line if body exists.
		$lines = $header;
		if ( ! empty( $body_lines ) ) {
			$lines[] = '';
			$lines   = array_merge( $lines, $body_lines );
		}

		return $lines;
	}

	/**
	 * Read current NFD block lines (without BEGIN/END markers).
	 *
	 * @param string $path .htaccess path.
	 * @return array Lines inside the marker block, or empty array if none.
	 */
	protected function get_current_block_lines( $path ) {
		$this->ensure_wp_file_helpers();

		$existing = array();
		if ( function_exists( 'extract_from_markers' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
			$existing = extract_from_markers( $path, $this->marker ); // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
			if ( ! is_array( $existing ) ) {
				$existing = array();
			}
		}
		return $existing;
	}

	/**
	 * Extract the checksum from an existing block (if present).
	 *
	 * @param array $lines Block lines.
	 * @return string sha256 or empty string if not found.
	 */
	protected function extract_hash_from_lines( $lines ) {
		if ( ! is_array( $lines ) ) {
			return '';
		}
		foreach ( $lines as $line ) {
			if ( preg_match( '/^\s*#\s*STATE\s+sha256:\s*([0-9a-f]{64})\b/i', $line, $m ) ) {
				return (string) $m[1];
			}
		}
		return '';
	}

	/**
	 * Normalize text to LF and trim trailing newlines.
	 *
	 * @param string $text Input.
	 * @return string Normalized text.
	 */
	protected function normalize( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = rtrim( $text, "\n" );
		return $text;
	}

	/**
	 * Locate .htaccess via WP helpers with ABSPATH fallback.
	 *
	 * @return string
	 */
	protected function get_htaccess_path() {
		$path = '';

		if ( function_exists( 'get_home_path' ) ) {
			$home = get_home_path();
			if ( is_string( $home ) && '' !== $home ) {
				$path = rtrim( $home, "/\\ \t\n\r\0\x0B" ) . DIRECTORY_SEPARATOR . '.htaccess';
			}
		}

		if ( '' === $path && defined( 'ABSPATH' ) ) {
			$path = rtrim( ABSPATH, "/\\ \t\n\r\0\x0B" ) . DIRECTORY_SEPARATOR . '.htaccess';
		}

		return $path;
	}

	/**
	 * Ensure WordPress marker helper functions are loaded.
	 *
	 * @return void
	 */
	protected function ensure_wp_file_helpers() {
		if ( ! function_exists( 'insert_with_markers' ) || ! function_exists( 'extract_from_markers' ) ) { // phpcs:ignore
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
	}
}
