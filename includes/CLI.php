<?php
/**
 * WP-CLI commands for the Htaccess module.
 *
 * Usage:
 *   wp newfold htaccess status
 *   wp newfold htaccess diagnose
 *   wp newfold htaccess scan
 *   wp newfold htaccess apply --version=1.0.0
 *   wp newfold htaccess remediate --version=1.0.0
 *   wp newfold htaccess restore --version=1.0.0
 *   wp newfold htaccess list-backups
 *
 * @package NewfoldLabs\WP\Module\Htaccess
 */

namespace NewfoldLabs\WP\Module\Htaccess;

use WP_CLI;
use WP_CLI\Utils;

if ( ! class_exists( '\WP_CLI' ) ) {
	return;
}

/**
 * Manage the Newfold-managed "NFD Htaccess" block.
 *
 * This command provides inspection and remediation helpers for the .htaccess file:
 * - Whole-file validation + loopback HTTP reachability
 * - Scan and self-heal ONLY the NFD-managed block (idempotent)
 * - Emergency restore from timestamped backups of the ENTIRE file
 *
 * Subcommands include: status, diagnose, scan, apply, remediate, restore, list-backups.
 */
class CLI {

	/**
	 * Registry service (provided by Api).
	 *
	 * @var Registry|null
	 */
	protected $registry;

	/**
	 * Updater service.
	 *
	 * @var Updater
	 */
	protected $updater;

	/**
	 * Validator service.
	 *
	 * @var Validator
	 */
	protected $validator;

	/**
	 * Scanner service.
	 *
	 * @var Scanner
	 */
	protected $scanner;

	/**
	 * Construct and wire services.
	 */
	public function __construct() {
		// Registry is exposed by Api.
		$this->registry = null;
		if ( class_exists( __NAMESPACE__ . '\Api' ) && method_exists( __NAMESPACE__ . '\Api', 'registry' ) ) {
			$this->registry = Api::registry();
		}

		// Local, standalone instances (do not rewrite the whole file).
		$this->updater   = new Updater();
		$this->validator = new Validator();
		$this->scanner   = new Scanner( $this->updater, $this->validator );
	}

	/**
	 * Combined quick status: diagnose (whole file + HTTP) and scan (NFD block).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render format for the summary. One of: table, json, yaml
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Quick status overview (whole-file + NFD block)
	 *     $ wp newfold htaccess status
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Associative arguments. May contain 'format'.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		$context   = $this->ctx();
		$fragments = $this->get_enabled_fragments( $context );

		$diag = $this->scanner->diagnose( $context );
		$scan = $this->scanner->scan( $context, $fragments );

		$rows = array(
			array(
				'key'   => 'file_valid',
				'value' => $diag['file_valid'] ? 'yes' : 'no',
			),
			array(
				'key'   => 'http_status',
				'value' => (string) $diag['http_status'],
			),
			array(
				'key'   => 'reachable',
				'value' => $diag['reachable'] ? 'yes' : 'no',
			),
			array(
				'key'   => 'nfd_status',
				'value' => $scan['status'],
			),
			array(
				'key'   => 'nfd_can_remediate',
				'value' => $scan['can_remediate'] ? 'yes' : 'no',
			),
		);

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		if ( 'table' === $format ) {
			Utils\format_items( 'table', $rows, array( 'key', 'value' ) );
			if ( ! empty( $diag['file_issues'] ) ) {
				WP_CLI::log( 'File issues:' );
				foreach ( $diag['file_issues'] as $issue ) {
					WP_CLI::log( ' - ' . $issue );
				}
			}
			if ( ! empty( $scan['issues'] ) ) {
				WP_CLI::log( 'NFD issues:' );
				foreach ( $scan['issues'] as $issue ) {
					WP_CLI::log( ' - ' . $issue );
				}
			}
		} else {
			$out = array(
				'diagnose' => $diag,
				'scan'     => $scan,
			);
			if ( 'json' === $format ) {
				WP_CLI::print_value( $out, array( 'format' => 'json' ) );
			} else {
				WP_CLI::print_value( $out, array( 'format' => 'yaml' ) );
			}
		}
	}

	/**
	 * Diagnose the current .htaccess (whole-file) and perform a loopback HTTP check.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp newfold htaccess diagnose
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Associative arguments. Unused.
	 * @return void
	 */
	public function diagnose( $args, $assoc_args ) {
		unset( $args, $assoc_args );

		$context = $this->ctx();
		$diag    = $this->scanner->diagnose( $context );

		Utils\format_items(
			'table',
			array(
				array(
					'key'   => 'file_valid',
					'value' => $diag['file_valid'] ? 'yes' : 'no',
				),
				array(
					'key'   => 'http_status',
					'value' => (string) $diag['http_status'],
				),
				array(
					'key'   => 'reachable',
					'value' => $diag['reachable'] ? 'yes' : 'no',
				),
			),
			array( 'key', 'value' )
		);

		if ( ! empty( $diag['file_issues'] ) ) {
			WP_CLI::warning( 'File issues:' );
			foreach ( $diag['file_issues'] as $issue ) {
				WP_CLI::log( ' - ' . $issue );
			}
		}
	}

	/**
	 * Scan ONLY the NFD-managed block for drift/corruption.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp newfold htaccess scan
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Associative arguments. Unused.
	 * @return void
	 */
	public function scan( $args, $assoc_args ) {
		unset( $args, $assoc_args );

		$context   = $this->ctx();
		$fragments = $this->get_enabled_fragments( $context );
		$scan      = $this->scanner->scan( $context, $fragments );

		Utils\format_items(
			'table',
			array(
				array(
					'key'   => 'status',
					'value' => $scan['status'],
				),
				array(
					'key'   => 'current_checksum',
					'value' => (string) $scan['current_checksum'],
				),
				array(
					'key'   => 'expected_checksum',
					'value' => (string) $scan['expected_checksum'],
				),
				array(
					'key'   => 'can_remediate',
					'value' => $scan['can_remediate'] ? 'yes' : 'no',
				),
			),
			array( 'key', 'value' )
		);

		if ( ! empty( $scan['issues'] ) ) {
			WP_CLI::warning( 'NFD issues:' );
			foreach ( $scan['issues'] as $issue ) {
				WP_CLI::log( ' - ' . $issue );
			}
		}
	}

	/**
	 * Apply the current fragments to the NFD block (idempotent safe write).
	 *
	 * ## OPTIONS
	 *
	 * [--version=<version>]
	 * : Version string written to the managed header. Default: 1.0.0
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp newfold htaccess apply --version=1.0.0
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Associative arguments. May contain 'version'.
	 * @return void
	 */
	public function apply( $args, $assoc_args ) {
		unset( $args );

		$version   = isset( $assoc_args['version'] ) ? (string) $assoc_args['version'] : '1.0.0';
		$context   = $this->ctx();
		$fragments = $this->get_enabled_fragments( $context );

		$ok = $this->scanner->remediate( $context, $fragments, $version );
		if ( $ok ) {
			WP_CLI::success( 'Applied NFD block successfully (or no-op if unchanged).' );
		} else {
			WP_CLI::error( 'Failed to apply NFD block.' );
		}
	}

	/**
	 * Remediate the NFD block if drift is detected (scan + apply if needed).
	 *
	 * ## OPTIONS
	 *
	 * [--version=<version>]
	 * : Version string written to the managed header. Default: 1.0.0
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp newfold htaccess remediate --version=1.0.0
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Associative arguments. May contain 'version'.
	 * @return void
	 */
	public function remediate( $args, $assoc_args ) {
		unset( $args );

		$version   = isset( $assoc_args['version'] ) ? (string) $assoc_args['version'] : '1.0.0';
		$context   = $this->ctx();
		$fragments = $this->get_enabled_fragments( $context );

		$scan = $this->scanner->scan( $context, $fragments );
		if ( ! empty( $scan['can_remediate'] ) ) {
			$ok = $this->scanner->remediate( $context, $fragments, $version );
			if ( $ok ) {
				WP_CLI::success( 'Remediation applied.' );
			} else {
				WP_CLI::error( 'Remediation failed.' );
			}
		} else {
			WP_CLI::success( 'No remediation needed.' );
		}
	}

	/**
	 * Restore the latest .htaccess backup (ENTIRE FILE), validate, and self-heal NFD.
	 *
	 * ## OPTIONS
	 *
	 * [--version=<version>]
	 * : Version string written to the managed header on re-apply. Default: 1.0.0
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp newfold htaccess restore --version=1.0.0
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Associative arguments. May contain 'version'.
	 * @return void
	 */
	public function restore( $args, $assoc_args ) {
		unset( $args );

		$version   = isset( $assoc_args['version'] ) ? (string) $assoc_args['version'] : '1.0.0';
		$context   = $this->ctx();
		$fragments = $this->get_enabled_fragments( $context );

		$report = $this->scanner->restore_latest_backup_verified( $context, $fragments, $version );

		Utils\format_items(
			'table',
			array(
				array(
					'key'   => 'restored',
					'value' => $report['restored'] ? 'yes' : 'no',
				),
				array(
					'key'   => 'restored_backup',
					'value' => (string) $report['restored_backup'],
				),
				array(
					'key'   => 'full_file_valid',
					'value' => $report['full_file_valid'] ? 'yes' : 'no',
				),
				array(
					'key'   => 'nfd_status',
					'value' => isset( $report['nfd_scan']['status'] ) ? $report['nfd_scan']['status'] : '',
				),
				array(
					'key'   => 'nfd_remediated',
					'value' => $report['nfd_remediated'] ? 'yes' : 'no',
				),
			),
			array( 'key', 'value' )
		);

		if ( ! empty( $report['precheck'] ) ) {
			WP_CLI::log( 'Precheck:' );
			WP_CLI::log( ' - file_valid: ' . ( $report['precheck']['file_valid'] ? 'yes' : 'no' ) );
			WP_CLI::log( ' - http_status: ' . (string) $report['precheck']['http_status'] );
			WP_CLI::log( ' - reachable: ' . ( $report['precheck']['reachable'] ? 'yes' : 'no' ) );
			if ( ! empty( $report['precheck']['file_issues'] ) ) {
				foreach ( $report['precheck']['file_issues'] as $iss ) {
					WP_CLI::log( '   * ' . $iss );
				}
			}
		}

		if ( ! empty( $report['full_file_issues'] ) ) {
			WP_CLI::warning( 'Full-file issues after restore:' );
			foreach ( $report['full_file_issues'] as $issue ) {
				WP_CLI::log( ' - ' . $issue );
			}
		}

		if ( isset( $report['nfd_scan']['issues'] ) && ! empty( $report['nfd_scan']['issues'] ) ) {
			WP_CLI::warning( 'NFD issues after restore:' );
			foreach ( $report['nfd_scan']['issues'] as $issue ) {
				WP_CLI::log( ' - ' . $issue );
			}
		}
	}

	/**
	 * List available .htaccess backups.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp newfold htaccess list-backups
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Associative arguments. Unused.
	 * @return void
	 */
	public function list_backups( $args, $assoc_args ) {
		unset( $args, $assoc_args );

		$rows = array();
		foreach ( $this->scanner->list_backups() as $name ) {
			$rows[] = array( 'backup' => $name );
		}
		if ( empty( $rows ) ) {
			WP_CLI::success( 'No backups found.' );
			return;
		}
		Utils\format_items( 'table', $rows, array( 'backup' ) );
	}

	/**
	 * Build a Context snapshot from WP.
	 *
	 * @return Context
	 */
	protected function ctx() {
		if ( class_exists( __NAMESPACE__ . '\Context' ) && method_exists( __NAMESPACE__ . '\Context', 'from_wp' ) ) {
			return Context::from_wp( array() );
		}
		// Extremely defensive fallback (should not happen with this module).
		return new Context( array() );
	}

	/**
	 * Fetch enabled fragments for the current site context.
	 *
	 * @param Context $context Context.
	 * @return Fragment[]
	 */
	protected function get_enabled_fragments( $context ) {
		if ( $this->registry && method_exists( $this->registry, 'enabled_fragments' ) ) {
			return $this->registry->enabled_fragments( $context );
		}

		// Fallback: empty array to avoid fatal; apply() / remediate() will no-op.
		return array();
	}
}

// Register the command after WordPress loads.
WP_CLI::add_command( 'newfold htaccess', __NAMESPACE__ . '\CLI' );
