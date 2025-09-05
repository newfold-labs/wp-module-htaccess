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
 * Class Updater
 *
 * @since 1.0.0
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
	 * @param string $body           Concatenated NFD fragments (no trailing newline required).
	 * @param string $host           Host label for header (e.g., example.com).
	 * @param string $version        Module version string for header.
	 * @param array  $legacy_labels  Optional array of legacy labels to remove (during Updater writes only).
	 * @return bool True on success, false on failure or no-op when unchanged.
	 */
	public function apply_managed_block( $body, $host, $version, $legacy_labels = array() ) {
		$path = $this->get_htaccess_path();
		if ( '' === $path ) {
			return false;
		}

		$this->ensure_wp_file_helpers();

		// Normalize incoming body and compute checksum.
		$body_norm   = Text::normalize_lf( (string) $body, true );
		$body_hash   = hash( 'sha256', $body_norm );
		$applied_iso = gmdate( 'Y-m-d\TH:i:s\Z' );

		// Build the lines we intend to write inside our markers.
		$lines = $this->build_block_lines( $body_norm, $host, $version, $body_hash, $applied_iso );

		// Read full current file once (LF-normalized).
		$current_full = $this->read_file( $path );
		$current_full = str_replace( array( "\r\n", "\r" ), "\n", $current_full );

		// Derive current block lines from the in-memory text (avoid extra disk IO).
		$pair  = $this->get_marker_regex_pair();
		$begin = $pair[0];
		$end   = $pair[1];

		$current_lines = array();
		if ( preg_match( $begin, $current_full, $mb, PREG_OFFSET_CAPTURE ) && preg_match( $end, $current_full, $me, PREG_OFFSET_CAPTURE ) ) {
			$start = $mb[0][1] + strlen( $mb[0][0] ) + 1; // after BEGIN line + newline
			$stop  = $me[0][1];                           // start of END line
			if ( $stop > $start ) {
				$inside        = substr( $current_full, $start, $stop - $start );
				$current_lines = explode( "\n", rtrim( $inside, "\n" ) );
			}
		}

		// Current block body hash for no-op check.
		$body_hash_current = '';
		if ( ! empty( $current_lines ) ) {
			$body_hash_current = $this->compute_body_hash_from_lines( $current_lines );
		}

		// Check if any legacy blocks exist that we plan to remove.
		$migrator   = new Migrator();
		$has_legacy = false;
		if ( is_array( $legacy_labels ) && ! empty( $legacy_labels ) ) {
			$probe      = $migrator->remove_legacy_blocks( $current_full, $legacy_labels );
			$has_legacy = ( ! empty( $probe['removed'] ) && $probe['removed'] > 0 );
		}

		// ---------- EMPTY BODY: delete the block instead of writing a blank block ----------
		if ( '' === $body_norm ) {
			// If no block and no legacy to remove, nothing to do.
			if ( empty( $current_lines ) && ! $has_legacy ) {
				return true;
			}

			// Refresh backup before modifying.
			if ( ! $this->refresh_single_backup( $path ) ) {
				return false;
			}

			// Start from current file, remove legacy, then remove managed block (all in-memory).
			$after_mig = $has_legacy ? $migrator->remove_legacy_blocks( $current_full, $legacy_labels ) : array( 'text' => $current_full );
			$txt       = $after_mig['text'];

			// Remove the managed block entirely (in-memory).
			if ( preg_match( $begin, $txt, $mb, PREG_OFFSET_CAPTURE ) && preg_match( $end, $txt, $me, PREG_OFFSET_CAPTURE ) ) {
				$start = $mb[0][1];
				$stop  = $me[0][1] + strlen( $me[0][0] );
				if ( $stop > $start ) {
					$txt = substr( $txt, 0, $start ) . substr( $txt, $stop );
				}
			}
			$txt = Text::collapse_excess_blanks( $txt );
			$txt = Text::ensure_single_trailing_newline( $txt );

			// Single atomic write.
			if ( ! $this->write_file_atomic( $path, $txt ) ) {
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

		// If body unchanged AND no legacy removals needed, no-op.
		if ( '' !== $body_hash_current && $body_hash_current === $body_hash && ! $has_legacy ) {
			return true;
		}

		// Refresh backup before modifying.
		if ( ! $this->refresh_single_backup( $path ) ) {
			return false;
		}

		// Remove legacy blocks (in-memory).
		$after_mig = $has_legacy
		? $migrator->remove_legacy_blocks( $current_full, $legacy_labels )
		: array(
			'text'    => $current_full,
			'removed' => 0,
		);

		// Inject/replace the managed block (in-memory).
		$final = $this->inject_or_replace_managed_block( $after_mig['text'], $lines );

		// Single atomic write to disk.
		if ( ! $this->write_file_atomic( $path, $final ) ) {
			return false;
		}

		// Post-write health check.
		if ( $this->scan_for_issues() ) {
			$this->restore_backup( $path );
			return false;
		}

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
		if ( ! function_exists( 'insert_with_markers' ) || ! function_exists( 'extract_from_markers' ) ) {
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
		$buf = file_get_contents( $path );
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

		// Atomic local write is intentional; WP_Filesystem isn't guaranteed or atomic.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $tmp, (string) $data ) ) {
			return false;
		}

		// We prefer POSIX-style rename for atomic replace on same filesystem.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! rename( $tmp, $path ) ) {
			wp_delete_file( $tmp );
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
		$body = Text::normalize_lf( $body, true );

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
		if ( function_exists( 'extract_from_markers' ) ) {
			$lines = extract_from_markers( $path, $this->marker );
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
	 * Build the full block text including BEGIN/END markers from body lines.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $lines Lines to place inside markers.
	 * @return string
	 */
	protected function render_markered_block( $lines ) {
		$payload = implode( "\n", (array) $lines );
		$payload = rtrim( $payload, "\n" );
		$out     = '# BEGIN ' . $this->marker . "\n" . $payload . "\n# END " . $this->marker . "\n";
		return $out;
	}

	/**
	 * Replace existing managed block or append a new one, returning full file text.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $current Full existing .htaccess (LF-normalized).
	 * @param string[] $lines   Lines for inside the NFD markers.
	 * @return string
	 */
	protected function inject_or_replace_managed_block( $current, $lines ) {
		$txt = (string) $current;

		$pair  = $this->get_marker_regex_pair();
		$begin = $pair[0];
		$end   = $pair[1];

		$block = $this->render_markered_block( $lines );

		// If there is an existing block, replace it.
		if ( preg_match( $begin, $txt, $mb, PREG_OFFSET_CAPTURE ) && preg_match( $end, $txt, $me, PREG_OFFSET_CAPTURE ) ) {
			$start = $mb[0][1];
			$stop  = $me[0][1] + strlen( $me[0][0] );
			if ( $stop > $start ) {
				$txt = substr( $txt, 0, $start ) . $block . substr( $txt, $stop );
			}
		} else {
			// Append with a separating newline if needed.
			if ( '' !== rtrim( $txt, "\n" ) ) {
				$txt .= "\n";
			}
			$txt .= $block;
		}

		// Normalize spacing and ensure trailing newline.
		$txt = Text::collapse_excess_blanks( $txt );

		return Text::ensure_single_trailing_newline( $txt );
	}

	/**
	 * Return regex patterns for BEGIN/END markers.
	 *
	 * @since 1.0.0
	 *
	 * @return array { string $begin, string $end }
	 */
	protected function get_marker_regex_pair() {
		return array(
			'/^\s*#\s*BEGIN\s+' . preg_quote( $this->marker, '/' ) . '\s*$/m',
			'/^\s*#\s*END\s+' . preg_quote( $this->marker, '/' ) . '\s*$/m',
		);
	}
}
