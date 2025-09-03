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

/**
 * Updater that manages ONLY the Newfold-managed block via markers,
 * leaving native WordPress rules and any other content untouched.
 *
 * Adds an in-block header and checksum, and skips writes if unchanged.
 * Maintains a single rolling backup refreshed before every write, validates after write via Scanner, and restores from backup on issues.
 *
 * @package NewfoldLabs\WP\Module\Htaccess
 */
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
		$current_lines     = $this->get_current_block_lines( $path );
		$body_hash_current = '';
		if ( ! empty( $current_lines ) ) {
			$body_hash_current = $this->compute_body_hash_from_lines( $current_lines );
		}

		// ---------- EMPTY BODY: delete the block instead of writing a blank block ----------
		if ( '' === $body_norm ) {
			// If no block present, nothing to do.
			if ( empty( $current_lines ) ) {
				return true;
			}

			// Refresh backup before modifying.
			if ( ! $this->refresh_single_backup( $path ) ) {
				return false;
			}

			// Remove the managed block entirely.
			$removed = $this->remove_managed_block( $path );
			if ( ! $removed ) {
				return false;
			}

			// Post-change health check.
			if ( $this->scan_for_issues() ) {
				$this->restore_backup( $path );
				return false;
			}

			return true;
		}
		// ---------- /EMPTY BODY ----------

		if ( '' !== $body_hash_current && $body_hash_current === $body_hash ) {
			return true; // no change (body identical even if header was altered)
		}

		// Refresh the single rolling backup to the latest current file before writing.
		// If creating/updating the backup fails, abort to avoid unsafe writes.
		if ( ! $this->refresh_single_backup( $path ) ) {
			return false;
		}

		// Insert/replace the block between our markers (preserves rest of file).
		$write_ok = (bool) insert_with_markers( $path, $this->marker, $lines ); // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		if ( ! $write_ok ) {
			return false;
		}

		// Post-write health check using Scanner (whole-file sanity  5xx reachability).
		$has_issues = $this->scan_for_issues();
		if ( $has_issues ) {
			// Restore original file from backup if issues are detected.
			$this->restore_backup( $path );
			return false;
		}

		// Backup is kept even when write is healthy (for later CRON use).
		return true;
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
		/**
		 * Compute the single backup file path for the given .htaccess.
		 *
		 * Uses a stable filename in the same directory to avoid creating many backups.
		 *
		 * @since 1.0.0
		 *
		 * @param string $path Htaccess file path.
		 * @return string Backup path.
		 */
	protected function get_backup_path( $path ) {
		$dir  = dirname( (string) $path );
		$name = '.htaccess.nfd-backup';
		return rtrim( $dir, "/\\ \t\n\r\0\x0B" ) . DIRECTORY_SEPARATOR . $name;
	}

	/**
	 * Refresh the single backup file to reflect the current .htaccess contents.
	 *
	 * This keeps exactly one backup (".htaccess.nfd-backup") that always contains
	 * the most recent pre-write state, avoiding filesystem bloat while ensuring
	 * we can roll back the last change.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Htaccess file path.
	 * @return bool True if backup was written (or updated), false on failure.
	 */
	protected function refresh_single_backup( $path ) {
		$backup = $this->get_backup_path( $path );

		// Read current .htaccess (empty string if missing/unreadable is acceptable).
		$current = $this->read_file( $path );

		// Overwrite or create the single backup with the latest contents.
		return $this->write_file_atomic( $backup, $current );
	}


	/**
	 * Restore the backup over the current .htaccess.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Htaccess file path.
	 * @return bool True on success, false otherwise.
	 */
	protected function restore_backup( $path ) {
		$backup = $this->get_backup_path( $path );
		if ( ! is_readable( $backup ) ) {
			return false;
		}
		$buf = $this->read_file( $backup );
		return $this->write_file_atomic( $path, $buf );
	}

	/**
	 * Read a file's contents safely. Returns empty string on failure.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path File path.
	 * @return string
	 */
	protected function read_file( $path ) {
		$path = (string) $path;
		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$buf = @file_get_contents( $path );
		return is_string( $buf ) ? $buf : '';
	}

	/**
	 * Write file contents atomically where possible.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Destination path.
	 * @param string $data Contents to write.
	 * @return bool True on success.
	 */
	protected function write_file_atomic( $path, $data ) {
		$path = (string) $path;
		$tmp  = $path . '.tmp-' . uniqid( 'nfd', true );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		if ( false === @file_put_contents( $tmp, (string) $data ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_function_rename
		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			return false;
		}

		return true;
	}

	/**
	 * Run a post-write health check using the Scanner.
	 *
	 * Criteria for "issues":
	 * - Whole-file validator fails, OR
	 * - Loopback HTTP status indicates server error (5xx).
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if issues were detected; false if file appears healthy.
	 */
	protected function scan_for_issues() {
		// Build a minimal context snapshot for the scanner.
		$context = class_exists( __NAMESPACE__ . '\Context' )
			? Context::from_wp( array() )
			: null;

		$scanner   = new Scanner( $this, new Validator() );
		$diagnosis = $scanner->diagnose( $context );

		$bad_file = ( empty( $diagnosis['file_valid'] ) );
		$bad_http = ( isset( $diagnosis['http_status'] ) && $diagnosis['http_status'] >= 500 && $diagnosis['http_status'] < 600 );

		return ( $bad_file || $bad_http );
	}

	/**
	 * Compute sha256 of the BODY within a marker block (ignores header comments).
	 *
	 * @since 1.0.0
	 *
	 * @param array $lines Lines inside the block (without BEGIN/END).
	 * @return string
	 */
	protected function compute_body_hash_from_lines( $lines ) {
		if ( ! is_array( $lines ) ) {
			return '';
		}

		$state_index = -1;
		foreach ( $lines as $i => $line ) {
			if ( preg_match( '/^\s*#\s*STATE\s+sha256:\s*[0-9a-f]{64}\b/i', $line ) ) {
				$state_index = $i;
				break;
			}
		}

		$start = ( -1 === $state_index ) ? 0 : $state_index + 1;
		if ( $start < count( $lines ) && '' === trim( $lines[ $start ] ) ) {
			++$start; // skip single blank separator after header
		}

		$body = implode( "\n", array_slice( $lines, $start ) );
		$body = str_replace( array( "\r\n", "\r" ), "\n", $body );
		$body = rtrim( $body, "\n" );

		return hash( 'sha256', $body );
	}

	/**
	 * Return the sha256 of the BODY currently inside the managed block on disk,
	 * ignoring the in-block header. Returns '' if the block is missing.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_current_body_hash() {
		$path = $this->get_htaccess_path();
		if ( '' === $path ) {
			return '';
		}

		$this->ensure_wp_file_helpers();

		$lines = array();
		if ( function_exists( 'extract_from_markers' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
			$lines = extract_from_markers( $path, $this->marker ); // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
			if ( ! is_array( $lines ) ) {
				$lines = array();
			}
		}

		// No block found.
		if ( empty( $lines ) ) {
			return '';
		}

		return $this->compute_body_hash_from_lines( $lines );
	}

	/**
	 * Remove the entire managed marker block (BEGIN..END) from .htaccess.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Absolute .htaccess path.
	 * @return bool True on success, false on failure.
	 */
	protected function remove_managed_block( $path ) {
		$path = (string) $path;
		if ( '' === $path || ! is_readable( $path ) || ! is_writable( $path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$buf = @file_get_contents( $path );
		if ( ! is_string( $buf ) ) {
			return false;
		}

		$nl  = "\n";
		$txt = str_replace( array( "\r\n", "\r" ), $nl, $buf );

		// Regex to remove the block including BEGIN/END lines (greedy across lines).
		$begin = preg_quote( '# BEGIN ' . $this->marker, '/' );
		$end   = preg_quote( '# END ' . $this->marker, '/' );
		$re    = '/^\s*' . $begin . '\s*$.*?^\s*' . $end . '\s*$/ms';

		$replaced = preg_replace( $re, '', $txt, 1, $count );
		if ( null === $replaced ) {
			return false;
		}
		if ( 0 === $count ) {
			// Nothing to remove; treat as success/no-op.
			return true;
		}

		// Collapse extra blank lines introduced by removal.
		$replaced = preg_replace( "/\n{3,}/", "\n\n", $replaced );
		$replaced = rtrim( $replaced, "\n" ) . $nl;

		return $this->write_file_atomic( $path, $replaced );
	}
}
