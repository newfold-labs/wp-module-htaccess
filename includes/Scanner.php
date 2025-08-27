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
 * - Inspect ONLY the managed "NFD Htaccess" block for drift/corruption.
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
	protected $marker = 'NFD Htaccess';

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
	 * Construct the scanner.
	 *
	 * @since 1.0.0
	 *
	 * @param Updater   $updater   Updater instance.
	 * @param Validator $validator Validator instance.
	 */
	public function __construct( Updater $updater, Validator $validator ) {
		$this->updater   = $updater;
		$this->validator = $validator;
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
		if ( '' === $text ) {
			$result['file_issues'][] = 'File is empty or unreadable.';
		} else {
			// Whole-file sanity (BEGIN/END balance, IfModule balance, flags/handlers).
			if ( $this->validator->is_valid( $text, array() ) ) {
				$result['file_valid'] = true;
			} else {
				$result['file_issues'] = $this->validator->get_errors();
			}
		}

		// Loopback HTTP check. 500s from Apache due to bad .htaccess happen before PHP.
		$home      = method_exists( $context, 'home_url' ) ? (string) $context->home_url() : '';
		$probe_url = ( '' !== $home ) ? $home . '/' : '/';

		$status                = $this->probe_http_status( $probe_url );
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
		$expected_body               = $this->compose_body_only( $fragments, $context );
		$expected_body_norm          = $this->normalize( $expected_body );
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
		$expected_body = $this->compose_body_only( $fragments, $context );
		$expected_body = $this->normalize( $expected_body );

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

		sort( $backups, SORT_STRING );
		$latest = end( $backups );
		if ( ! $latest ) {
			$result['full_file_issues'][] = 'Failed to identify latest backup.';
			return $result;
		}

		$dir = dirname( $path );
		$src = $dir . DIRECTORY_SEPARATOR . $latest;

		if ( ! $this->copy_overwrite( $src, $path ) ) {
			$result['full_file_issues'][] = 'Backup restore failed.';
			return $result;
		}

		$result['restored']        = true;
		$result['restored_backup'] = $latest;

		// ---- Validate the restored full file.
		$text = $this->read_file( $path );
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
	 * @return string[] Filenames only.
	 */
	public function list_backups() {
		$path = $this->get_htaccess_path();
		if ( '' === $path ) {
			return array();
		}

		$dir   = dirname( $path );
		$files = $this->scan_dir( $dir );
		$out   = array();

		foreach ( $files as $name ) {
			if ( preg_match( '/^\.htaccess\.\d{8}-\d{6}\.bak$/', $name ) ) {
				$out[] = $name;
			}
		}

		return $out;
	}

	/**
	 * Compose fragments into a single body without the NFD header
	 * (mirrors Manager::compose_body_only for scanner autonomy).
	 *
	 * @since 1.0.0
	 *
	 * @param Fragment[] $fragments Fragments to render.
	 * @param mixed      $context   Context.
	 * @return string Body text (no trailing newline).
	 */
	protected function compose_body_only( $fragments, $context ) {
		$blocks = array();

		if ( is_array( $fragments ) ) {
			foreach ( $fragments as $fragment ) {
				if ( ! $fragment instanceof Fragment ) {
					continue;
				}
				$rendered = (string) $fragment->render( $context );
				$rendered = $this->normalize( $rendered );
				$rendered = preg_replace( '/^\s+|\s+$/u', '', $rendered );
				if ( '' !== $rendered ) {
					$blocks[] = $rendered;
				}
			}
		}

		$body = implode( "\n\n", $blocks );
		$body = rtrim( $body, "\n" );
		return $body;
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
	 * @since 1.0.0
	 *
	 * @param string $path   .htaccess path.
	 * @param string $marker Marker label.
	 * @return array Lines inside the block (without BEGIN/END) or empty array.
	 */
	protected function extract_marker_lines( $path, $marker ) {
		$this->ensure_wp_file_helpers();

		if ( ! function_exists( 'extract_from_markers' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
			return array();
		}

		$lines = extract_from_markers( $path, $marker ); // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		return is_array( $lines ) ? $lines : array();
	}

	/**
	 * Normalize text to LF and trim trailing newlines.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Input.
	 * @return string Normalized.
	 */
	protected function normalize( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$text = rtrim( $text, "\n" );
		return $text;
	}

	/**
	 * Resolve .htaccess path via WordPress helper with ABSPATH fallback.
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

		return $path;
	}

	/**
	 * Ensure WP marker helpers are available.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function ensure_wp_file_helpers() {
		if ( ! function_exists( 'insert_with_markers' ) || ! function_exists( 'extract_from_markers' ) ) { // phpcs:ignore
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
	}

	/**
	 * Read an entire file into a string (safely).
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	protected function read_file( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$buf = @file_get_contents( $path );
		return is_string( $buf ) ? $buf : '';
	}

	/**
	 * HEAD request to detect 5xx quickly (best-effort).
	 *
	 * @since 1.0.0
	 *
	 * @param string $url URL to test.
	 * @return int HTTP status code (0 if request failed).
	 */
	protected function probe_http_status( $url ) {
		if ( ! function_exists( 'wp_remote_request' ) ) {
			return 0;
		}

		$args = array(
			'method'      => 'HEAD',
			'timeout'     => 5,
			'redirection' => 0,
			'blocking'    => true,
			// In dev (LocalWP/self-signed), we relax SSL; in prod you may set true.
			'sslverify'   => false,
		);

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return 0;
		}

		$code = wp_remote_retrieve_response_code( $response );
		return (int) $code;
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		$ok = @copy( $src, $dst );
		if ( $ok ) {
			@chmod( $dst, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return (bool) $ok;
	}

	/**
	 * Lightweight directory scan (names only).
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir Directory path.
	 * @return string[] Filenames (no paths).
	 */
	protected function scan_dir( $dir ) {
		$list = array();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.directory_functions_opendir
		$dh = @opendir( $dir );
		if ( false === $dh ) {
			return $list;
		}

		// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( false !== ( $name = @readdir( $dh ) ) ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$list[] = $name;
		}

		@closedir( $dh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.directory_functions_closedir
		return $list;
	}
}
