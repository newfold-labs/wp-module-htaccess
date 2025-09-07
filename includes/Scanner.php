<?php
/**
 * Scanner for the Htaccess module.
 *
 * @package NewfoldLabs\WP\Module\Htaccess
 */

namespace NewfoldLabs\WP\Module\Htaccess;

/**
 * Scanner for the Htaccess module.
 *
 * Responsibilities:
 * - Diagnose the current .htaccess for whole-file sanity & HTTP reachability.
 * - Inspect ONLY the managed marker block for drift/corruption.
 * - Remediate our block by reapplying the composed NFD body via Updater.
 * - Restore the latest timestamped backup of the ENTIRE .htaccess, then verify,
 *   and finally re-check/remediate our NFD block.
 *
 * Notes:
 * - Day-to-day self-heal: validate/remediate ONLY the NFD block.
 * - Emergency restore: replace the ENTIRE file from a .bak, then validate and heal our block.
 *
 * @since 1.0.0
 */
class Scanner {
	/**
	 * Marker label used to bracket our managed section.
	 *
	 * @var string
	 */
	protected $marker;

	/**
	 * Updater service for marker-based writes.
	 *
	 * @var Updater
	 */
	protected $updater;

	/**
	 * Validator service for syntax checks.
	 *
	 * @var Validator
	 */
	protected $validator;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Updater   $updater   Updater instance.
	 * @param Validator $validator Validator instance.
	 * @param string    $marker    Optional marker label. Defaults to Config::marker().
	 */
	public function __construct( Updater $updater, Validator $validator, $marker = null ) {
		$this->updater   = $updater;
		$this->validator = $validator;
		$this->marker    = ( null !== $marker ) ? (string) $marker : Config::marker();
	}

	/**
	 * Diagnose current .htaccess (whole-file) + loopback HTTP reachability.
	 *
	 * Use this BEFORE doing risky operations (e.g., restore).
	 *
	 * @since 1.0.0
	 *
	 * @param Context $context Context snapshot.
	 * @return array {
	 *   @type bool     file_valid   True if whole-file validator passes.
	 *   @type string[] file_issues  Validator errors (empty if none).
	 *   @type int      http_status  HTTP status code from HEAD to home (0 if request failed).
	 *   @type bool     reachable    True if status is 200-399 (best-effort).
	 * }
	 */
	public function diagnose( $context ) {
		$result = array(
			'file_valid'  => false,
			'file_issues' => array(),
			'http_status' => 0,
			'reachable'   => false,
		);

		$path = $this->get_htaccess_path();
		if ( '' === $path ) {
			$result['file_issues'][] = 'Could not resolve .htaccess path.';
			return $result;
		}

		$text = $this->read_file( $path );
		$text = Text::normalize_lf( $text, true );
		if ( '' === $text ) {
			$result['file_issues'][] = 'File is empty or unreadable.';
		} elseif ( $this->validator->is_valid( $text, array() ) ) {
			// Whole-file sanity (BEGIN/END balance, IfModule balance, flags/handlers).
			$result['file_valid'] = true;
		} else {
			$result['file_issues'] = $this->validator->get_errors();
		}

		// Loopback HTTP check. 500s from Apache due to bad .htaccess happen before PHP.
		$home = method_exists( $context, 'home_url' ) ? (string) $context->home_url() : '';

		// If home URL is not available, try site URL.
		if ( '' === $home && method_exists( $context, 'site_url' ) ) {
			$home = (string) $context->site_url();
		}

		if ( '' !== $home ) {
			// Ensure absolute, normalized URL ending with a slash.
			$probe_url = rtrim( $home, '/' ) . '/';
			$status    = $this->probe_http_status( $probe_url );
		} else {
			// No absolute URL available; skip probe.
			$status = 0;
		}

		$result['http_status'] = $status;
		$result['reachable']   = ( $status >= 200 && $status < 400 );

		return $result;
	}

	/**
	 * Scan ONLY the NFD managed block for drift/corruption.
	 *
	 * @since 1.0.0
	 *
	 * @param Context    $context   Context snapshot.
	 * @param Fragment[] $fragments Enabled NFD fragments to compare against.
	 * @return array {
	 *   @type string   status            One of 'ok', 'missing', 'mismatch', 'invalid', 'error'.
	 *   @type string[] issues            Human-readable issues detected (may be empty).
	 *   @type string   current_checksum  Current checksum found in file (or empty).
	 *   @type string   expected_checksum Checksum of recomposed body (or empty).
	 *   @type bool     can_remediate     True if a remediation apply should fix drift.
	 * }
	 */
	public function scan( $context, $fragments ) {
		$report = array(
			'status'            => 'ok',
			'issues'            => array(),
			'current_checksum'  => '',
			'expected_checksum' => '',
			'can_remediate'     => false,
		);

		$path = $this->get_htaccess_path();
		if ( '' === $path ) {
			$report['status']   = 'error';
			$report['issues'][] = 'Could not resolve .htaccess path.';
			return $report;
		}

		$this->ensure_wp_file_helpers();

		// Current lines inside our marker block.
		$current_lines = $this->extract_marker_lines( $path, $this->marker );

		if ( empty( $current_lines ) ) {
			$report['status']   = 'missing';
			$report['issues'][] = 'Managed NFD block not found.';
		}

		$current_hash               = $this->extract_hash_from_lines( $current_lines );
		$report['current_checksum'] = $current_hash;

		// Build the expected body from provided fragments.
		$expected_body               = Composer::compose_body_only( $fragments, $context );
		$expected_body_norm          = Text::normalize_lf( $expected_body, true );
		$expected_hash               = hash( 'sha256', $expected_body_norm );
		$report['expected_checksum'] = $expected_hash;

		// Validate expected body (light checks).
		if ( ! $this->validator->is_valid( $expected_body_norm, array() ) ) {
			$report['status']   = 'invalid';
			$report['issues'][] = 'Expected body did not pass validation: ' . implode( ' | ', $this->validator->get_errors() );

			// Try remediation of expected body for future apply().
			$expected_body_norm = $this->validator->remediate( $expected_body_norm );
			if ( ! $this->validator->is_valid( $expected_body_norm, array() ) ) {
				$report['issues'][] = 'Expected body remained invalid after remediation.';
				return $report;
			}

			$expected_hash               = hash( 'sha256', $expected_body_norm );
			$report['expected_checksum'] = $expected_hash;
		}

		// Decide status if not already error/invalid/missing.
		if ( 'ok' === $report['status'] && '' !== $expected_hash ) {
			if ( '' === $current_hash ) {
				$report['status']   = 'missing';
				$report['issues'][] = 'Managed NFD block checksum not found; block may be missing or uninitialized.';
			} elseif ( $current_hash !== $expected_hash ) {
				$report['status']   = 'mismatch';
				$report['issues'][] = 'Managed NFD block checksum mismatch (drift detected).';
			}
		}

		// If missing or mismatch, we can remediate by re-applying the expected body.
		if ( 'missing' === $report['status'] || 'mismatch' === $report['status'] ) {
			$report['can_remediate'] = true;
		}

		return $report;
	}

	/**
	 * Remediate the NFD block by recomposing from fragments and writing via Updater.
	 *
	 * @since 1.0.0
	 *
	 * @param Context    $context   Context snapshot.
	 * @param Fragment[] $fragments Enabled NFD fragments to write.
	 * @param string     $version   Module version string for header.
	 * @return bool True on success, false on failure.
	 */
	public function remediate( $context, $fragments, $version ) {
		$host          = $context->host();
		$expected_body = Composer::compose_body_only( $fragments, $context );
		$expected_body = Text::normalize_lf( $expected_body, true );

		// Validate/remediate expected body before writing.
		if ( ! $this->validator->is_valid( $expected_body, array() ) ) {
			$expected_body = $this->validator->remediate( $expected_body );
			if ( ! $this->validator->is_valid( $expected_body, array() ) ) {
				return false;
			}
		}

		// Updater will embed header + checksum and no-op if identical.
		return (bool) $this->updater->apply_managed_block( $expected_body, $host, $version );
	}

	/**
	 * Restore the latest .htaccess backup (ENTIRE FILE) with verification and NFD self-heal.
	 *
	 * Steps:
	 *  - Pre-check: whole-file validation + loopback HTTP HEAD; only restore if broken.
	 *  - Restore latest .htaccess.YYYYMMDD-HHMMSS.bak over .htaccess.
	 *  - Validate the restored full file.
	 *  - Re-scan the NFD block and remediate it if needed.
	 *
	 * @since 1.0.0
	 *
	 * @param Context    $context   Context snapshot.
	 * @param Fragment[] $fragments Enabled NFD fragments used to recompose our block.
	 * @param string     $version   Module version string for the NFD header.
	 * @return array {
	 *   @type bool     restored           True if a backup was restored.
	 *   @type string   restored_backup    Filename of the backup restored (if any).
	 *   @type bool     full_file_valid    True if whole-file validation passed after restore (or precheck when no restore).
	 *   @type string[] full_file_issues   Validator errors for the restored/current file (if any).
	 *   @type array    nfd_scan           Result of Scanner::scan() after (or without) restore.
	 *   @type bool     nfd_remediated     True if we re-applied our NFD block.
	 *   @type array    precheck           Diagnose() result prior to restore attempt.
	 * }
	 */
	public function restore_latest_backup_verified( $context, $fragments, $version ) {
		$result = array(
			'restored'         => false,
			'restored_backup'  => '',
			'full_file_valid'  => false,
			'full_file_issues' => array(),
			'nfd_scan'         => array(),
			'nfd_remediated'   => false,
			'precheck'         => array(),
		);

		// ---- Pre-check: restore only if clearly broken.
		$pre                = $this->diagnose( $context );
		$result['precheck'] = $pre;
		$needs_restore      = ( ! $pre['file_valid'] ) || ( $pre['http_status'] >= 500 && $pre['http_status'] < 600 );

		if ( ! $needs_restore ) {
			// No restore needed; still ensure our NFD block is healthy.
			$scan               = $this->scan( $context, $fragments );
			$result['nfd_scan'] = $scan;

			if ( ! empty( $scan['can_remediate'] ) ) {
				$result['nfd_remediated'] = (bool) $this->remediate( $context, $fragments, $version );
			}

			$result['full_file_valid']  = $pre['file_valid'];
			$result['full_file_issues'] = $pre['file_issues'];
			return $result;
		}

		// ---- Proceed with restore of latest .bak
		$path = $this->get_htaccess_path();
		if ( '' === $path ) {
			$result['full_file_issues'][] = 'Could not resolve .htaccess path.';
			return $result;
		}

		$backups = $this->list_backups();
		if ( empty( $backups ) ) {
			$result['full_file_issues'][] = 'No backups found.';
			return $result;
		}

		// Newest first, take index 0.
		$latest = $backups[0];
		$dir    = dirname( $path );
		$src    = $dir . DIRECTORY_SEPARATOR . $latest;

		if ( ! file_exists( $src ) || ! is_readable( $src ) ) {
			$result['full_file_issues'][] = 'Latest backup is missing or unreadable.';
			return $result;
		}

		if ( ! $this->copy_overwrite( $src, $path ) ) {
			$result['full_file_issues'][] = 'Backup restore failed.';
			return $result;
		}

		$result['restored']        = true;
		$result['restored_backup'] = $latest;

		// ---- Validate the restored full file.
		$text = $this->read_file( $path );
		$text = Text::normalize_lf( $text, true );
		if ( '' === $text ) {
			$result['full_file_issues'][] = 'Restored file is empty or unreadable.';
		} elseif ( $this->validator->is_valid( $text, array() ) ) {
				$result['full_file_valid'] = true;
		} else {
			$result['full_file_valid']  = false;
			$result['full_file_issues'] = $this->validator->get_errors();
		}

		// ---- Re-check and self-heal our NFD block post-restore.
		$scan               = $this->scan( $context, $fragments );
		$result['nfd_scan'] = $scan;

		if ( ! empty( $scan['can_remediate'] ) ) {
			$result['nfd_remediated'] = (bool) $this->remediate( $context, $fragments, $version );
		}

		return $result;
	}

	/**
	 * List available .htaccess backups in the home directory.
	 *
	 * Pattern: .htaccess.YYYYMMDD-HHMMSS.bak
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Filenames only, sorted DESC by timestamp (newest first).
	 */
	public function list_backups() {
		$path = $this->get_htaccess_path();
		if ( '' === $path ) {
			return array();
		}

		$dir   = dirname( $path );
		$files = $this->scan_dir( $dir );
		$items = array();

		foreach ( $files as $name ) {
			if ( preg_match( '/^\.htaccess\.(\d{8})-(\d{6})\.bak$/', $name, $m ) ) {
				// Build sortable numeric timestamp (UTC-like ordering).
				$ts_str  = $m[1] . $m[2]; // YYYYMMDDHHMMSS
				$ts_num  = (int) $ts_str;
				$items[] = array(
					'name' => $name,
					'ts'   => $ts_num,
				);
			}
		}

		if ( empty( $items ) ) {
			return array();
		}

		// Sort newest first.
		usort(
			$items,
			function ( $a, $b ) {
				if ( $a['ts'] === $b['ts'] ) {
					// Stable tie-breaker by name to keep deterministic.
					return strcmp( $b['name'], $a['name'] );
				}
				return ( $a['ts'] < $b['ts'] ) ? 1 : -1;
			}
		);

		// Return only filenames.
		$out = array();
		foreach ( $items as $it ) {
			$out[] = $it['name'];
		}
		return $out;
	}

	/**
	 * Extract the NFD block’s checksum from marker lines.
	 *
	 * @since 1.0.0
	 *
	 * @param array $lines Lines inside the marker block.
	 * @return string sha256 or empty string.
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
	 * Extract lines inside a marker block from an .htaccess file.
	 *
	 * Preserves all inner lines (including nested # BEGIN/# END and comment lines).
	 * Falls back to WP core extract_from_markers() if the Text helper isn't available.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Use Text::extract_from_markers_raw() for consistent parsing.
	 *
	 * @param string $path   Absolute .htaccess path.
	 * @param string $marker Marker label.
	 * @return string[] Lines inside the block (without the outer BEGIN/END) or empty array.
	 */
	protected function extract_marker_lines( $path, $marker ) {
		$path   = (string) $path;
		$marker = (string) $marker;

		if ( '' === $path || '' === $marker ) {
			return array();
		}

		// Prefer our own extractor (preserves all inner content).
		if ( class_exists( __NAMESPACE__ . '\Text' ) && method_exists( __NAMESPACE__ . '\Text', 'extract_from_markers_raw' ) ) {
			$buf = $this->read_file( $path );
			if ( '' === $buf ) {
				return array();
			}
			$lines = Text::extract_from_markers_raw( $buf, $marker );
			return is_array( $lines ) ? $lines : array();
		}

		// Fallback to WP core behavior (skips comment lines inside the block).
		$this->ensure_wp_file_helpers();
		if ( function_exists( 'extract_from_markers' ) ) {
			$lines = extract_from_markers( $path, $marker );
			return is_array( $lines ) ? $lines : array();
		}

		return array();
	}

	/**
	 * Resolve .htaccess path via WordPress helper with ABSPATH fallback.
	 *
	 * @since 1.0.0
	 *
	 * @return string Absolute path or empty string.
	 */
	/**
	 * Resolve .htaccess path via WordPress helper with ABSPATH fallback.
	 * Prefers a Context-provided path if available.
	 *
	 * @since 1.0.0
	 *
	 * @return string Absolute path or empty string.
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

		// Just return the computed path; callers decide whether it's readable.
		return (string) $path;
	}


	/**
	 * Ensure WP marker helpers are available.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function ensure_wp_file_helpers() {
		if ( ! function_exists( 'insert_with_markers' ) || ! function_exists( 'extract_from_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
	}

	/**
	 * Read an entire file into a string, preferring WP_Filesystem.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Absolute path.
	 * @return string File contents or empty string on failure.
	 */
	protected function read_file( $path ) {
		$path = (string) $path;
		if ( '' === $path ) {
			return '';
		}

		// Prefer WP_Filesystem if available and initialized.
		if ( function_exists( 'WP_Filesystem' ) ) {
			global $wp_filesystem;

			if ( ! $wp_filesystem ) {
				// Bootstrap FS API (quietly).
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			if ( $wp_filesystem && is_object( $wp_filesystem ) ) {
				if ( ! $wp_filesystem->exists( $path ) || ! $wp_filesystem->is_readable( $path ) ) {
					return '';
				}
				$buf = $wp_filesystem->get_contents( $path );
				return is_string( $buf ) ? $buf : '';
			}
		}

		// Fallback: native read with explicit guards (no @ suppression).
		if ( ! is_readable( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$buf = file_get_contents( $path );

		return is_string( $buf ) ? $buf : '';
	}

	/**
	 * HEAD request to detect 5xx quickly (best-effort).
	 *
	 * Tries HEAD first, then falls back to GET if HEAD is blocked.
	 * Uses secure defaults and follows a few redirects.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url Absolute URL to test.
	 * @return int HTTP status code (0 if request failed).
	 */
	protected function probe_http_status( $url ) {
		if ( ! function_exists( 'wp_remote_request' ) ) {
			return 0;
		}

		// Secure and resilient defaults.
		$args = array(
			'method'      => 'HEAD',
			'timeout'     => 5,
			'redirection' => 3, // follow a few redirects
			'blocking'    => true,
			'sslverify'   => true, // prefer secure by default
			'user-agent'  => 'Newfold Htaccess Scanner',
		);

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			// Some environments block HEAD; try a short GET as fallback.
			$args['method'] = 'GET';
			$response       = wp_remote_request( $url, $args );
			if ( is_wp_error( $response ) ) {
				return 0;
			}
		}

		return (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Copy a file over another (overwrite).
	 *
	 * @since 1.0.0
	 *
	 * @param string $src Source path.
	 * @param string $dst Destination path.
	 * @return bool
	 */
	protected function copy_overwrite( $src, $dst ) {
		// Prefer WP_Filesystem if available.
		if ( function_exists( 'WP_Filesystem' ) ) {
			global $wp_filesystem;

			if ( ! $wp_filesystem ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			if ( $wp_filesystem && is_object( $wp_filesystem ) ) {
				$ok = $wp_filesystem->copy( $src, $dst, true, FS_CHMOD_FILE );
				return (bool) $ok;
			}
		}

		// Fallback to native copy + chmod.
		$ok = copy( $src, $dst );
		if ( $ok && function_exists( 'chmod' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			chmod( $dst, 0644 );
		}
		return (bool) $ok;
	}

	/**
	 * Lightweight directory scan (names only).
	 *
	 * Prefers WP_Filesystem; falls back to native ops.
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir Directory path.
	 * @return string[] Filenames (no paths).
	 */
	protected function scan_dir( $dir ) {
		$dir  = (string) $dir;
		$list = array();

		// Prefer WP_Filesystem if available and initialized.
		if ( function_exists( 'WP_Filesystem' ) ) {
			global $wp_filesystem;

			if ( ! $wp_filesystem ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			if ( $wp_filesystem && is_object( $wp_filesystem ) ) {
				$entries = $wp_filesystem->dirlist( $dir );
				if ( is_array( $entries ) ) {
					foreach ( array_keys( $entries ) as $name ) {
						if ( '.' === $name || '..' === $name ) {
							continue;
						}
						$list[] = $name;
					}
				}
				return $list;
			}
		}

		// Fallback: native directory functions (guarded; minimal phpcs ignores).
		if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			return $list;
		}

		$dh = opendir( $dir );
		if ( false === $dh ) {
			return $list;
		}

		// Pre-read to avoid assignment in the while condition.
		$name = readdir( $dh );
		while ( false !== $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				$list[] = $name;
			}

			$name = readdir( $dh );
		}

		closedir( $dh );

		return $list;
	}
}
