<?php
/**
 * Htaccess Manager Module.
 *
 * @package NewfoldLabs\WP\Module\Htaccess
 */

namespace NewfoldLabs\WP\Module\Htaccess;

use NewfoldLabs\WP\ModuleLoader\Container;
use WP_CLI;

/**
 * Manages all functionality for the Htaccess module.
 *
 * @since 1.0.0
 */
class Manager {

	/**
	 * Dependency injection container.
	 *
	 * @var Container
	 */
	protected $container;

	/**
	 * Registry of fragments.
	 *
	 * @var Registry
	 */
	protected $registry;

	/**
	 * Composer service.
	 *
	 * @var Composer
	 */
	protected $composer;

	/**
	 * Validator service.
	 *
	 * @var Validator
	 */
	protected $validator;

	/**
	 * Writer service.
	 *
	 * @var Writer
	 */
	protected $writer;

	/**
	 * Updater service (marker-based merge).
	 *
	 * @var Updater
	 */
	protected $updater;

	/**
	 * Whether or not an apply is queued (dirty state).
	 *
	 * @var bool
	 */
	protected $dirty = false;

	/**
	 * Constructor.
	 *
	 * Keep construction minimal; heavy lifting happens in boot().
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container Module container instance.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Module bootstrap. Attach hooks and initialize services here.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function boot() {
		// Instantiate internal services.
		$this->registry  = new Registry();
		$this->composer  = new Composer();
		$this->validator = new Validator();
		$this->writer    = new Writer();
		$this->updater   = new Updater();

		// Expose to static API for other modules to use without the container.
		Api::set_registry( $this->registry );
		Api::set_manager( $this );

		// Queue on common events that affect rewrite rules or fragments.
		add_action( 'switch_theme', array( $this, 'queue_apply' ) );
		add_action( 'activated_plugin', array( $this, 'queue_apply' ) );
		add_action( 'deactivated_plugin', array( $this, 'queue_apply' ) );
		add_action( 'permalink_structure_changed', array( $this, 'queue_apply' ) );

		// Option watcher (e.g., permalink, home/siteurl, module toggles).
		add_action( 'updated_option', array( $this, 'maybe_queue_on_option' ), 10, 3 );

		// Single debounced write at safe boundaries (admin/cron/CLI only).
		add_action( 'shutdown', array( $this, 'maybe_apply_on_shutdown' ), 0 );

		WP_CLI::add_command( 'newfold htaccess', CLI::class );
	}

	/**
	 * Queue a rebuild/apply of the canonical .htaccess state.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $reason Optional reason for logging/telemetry.
	 * @return void
	 */
	public function queue_apply( $reason = '' ) {
		$payload = array(
			'at'     => time(),
			'reason' => is_scalar( $reason ) ? (string) $reason : '',
		);

		set_site_transient( 'nfd_htaccess_needs_update', $payload, 5 * MINUTE_IN_SECONDS );
		$this->mark_dirty();
	}

	/**
	 * Option change watcher: queue apply when relevant options change.
	 *
	 * @since 1.0.0
	 *
	 * @param string $option    Option name.
	 * @return void
	 */
	public function maybe_queue_on_option( $option ) {
		if ( 'permalink_structure' === $option || 'home' === $option || 'siteurl' === $option ) {
			$this->queue_apply( 'option:' . $option );
			return;
		}
	}

	/**
	 * Is this a safe context to apply at shutdown?
	 *
	 * @return bool
	 */
	protected function is_safe_context() {
		$is_cli  = ( defined( 'WP_CLI' ) && WP_CLI );
		$is_rest = ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		$is_ajax = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() );

		// Admin screens (wp-admin), cron, CLI, REST API, AJAX are all safe.
		if ( is_admin() || wp_doing_cron() || $is_cli || $is_rest || $is_ajax ) {
			return true;
		}

		return false;
	}

	/**
	 * Mark the manager as dirty (needs apply).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function mark_dirty() {
		$this->dirty = true;
	}

	/**
	 * Debounced apply on shutdown, only in safe contexts.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_apply_on_shutdown() {
		if ( ! $this->is_safe_context() || ! $this->dirty ) {
			return;
		}
		delete_site_transient( 'nfd_htaccess_needs_update' );
		$this->dirty = false;
		$this->apply_canonical_state();
	}

	/**
	 * Acquire a write lock to prevent concurrent writes.
	 *
	 * @return bool True if lock acquired, false if already locked.
	 */
	protected function acquire_lock() {
		return (bool) set_transient( 'nfd_htaccess_write_lock', 1, 30 ); // 30s
	}

	/**
	 * Release the write lock.
	 *
	 * @return void
	 */
	protected function release_lock() {
		delete_transient( 'nfd_htaccess_write_lock' );
	}


	/**
	 * Compose, validate, and merge ONLY the NFD-managed block into .htaccess.
	 *
	 * - Gathers enabled fragments from the registry.
	 * - Skips any WordPress-related fragments (we don't manage core rules).
	 * - Enforces exclusivity (first one wins for exclusive fragments).
	 * - Composes a single NFD body (no top-level header).
	 * - Validates and remediates common issues.
	 * - Writes into "# BEGIN NFD Htaccess" markers with in-block header + checksum.
	 * - No-ops automatically if checksum unchanged (handled in Updater).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function apply_canonical_state() {
		// Optional debug helper.
		$log = function ( $msg ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[htaccess-manager] ' . $msg );
			}
		};

		if ( ! $this->acquire_lock() ) {
			return; // another request is writing
		}
		try {
				// 1) Build context and prep metadata.
			$context = Context::from_wp( array() );
			$host    = $context->host();
			$version = '1.0.0';

			// 2) Collect enabled fragments and drop WordPress-related ones.
			$all       = $this->registry->enabled_fragments( $context );
			$fragments = array();
			$seen_excl = array(); // exclusivity guard by fragment id

			foreach ( $all as $f ) {
				$id = method_exists( $f, 'id' ) ? (string) $f->id() : '';

				// Skip anything WordPress-y: we do NOT manage core rules here.
				if ( '' !== $id && ( 'WordPress.core' === $id || false !== strpos( $id, 'WordPress' ) ) ) {
					continue;
				}

				// Enforce "exclusive" fragments: keep the first seen.
				$is_exclusive = ( method_exists( $f, 'exclusive' ) && true === $f->exclusive() );
				if ( $is_exclusive ) {
					if ( '' !== $id && isset( $seen_excl[ $id ] ) ) {
						continue; // drop duplicates of exclusive fragments
					}
					if ( '' !== $id ) {
						$seen_excl[ $id ] = true;
					}
				}

				$fragments[] = $f;
			}

			$log(
				'enabled NFD fragments: ' . count( $fragments ) . ( count( $fragments ) ? ' [' . implode(
					',',
					array_map(
						function ( $f ) {
							return method_exists( $f, 'id' ) ? $f->id() : get_class( $f );
						},
						$fragments
					)
				) . ']' : '' )
			);

			// 3) Compose body only (no global header; Updater adds an in-block header).
			$body = $this->compose_body_only( $fragments, $context );

			// 4) Validate & remediate (lightweight: flags, handlers, BEGIN/END balance within body).
			// We pass an empty exclusives list here because exclusivity was already enforced above.
			$is_valid = $this->validator->is_valid( $body, array() );

			if ( ! $is_valid ) {
				$log( 'validator errors: ' . implode( ' | ', $this->validator->get_errors() ) );
				$body = $this->validator->remediate( $body );

				// Re-check after remediation; if still invalid, abort to avoid breaking .htaccess.
				if ( ! $this->validator->is_valid( $body, array() ) ) {
					$log( 'validation failed after remediation; skipping write' );
					return;
				}
			}

			// 5) Merge into the managed marker block with checksum; Updater no-ops if unchanged.
			$ok = $this->updater->apply_managed_block( $body, $host, $version );

			$log( 'merge result: ' . ( $ok ? 'ok' : 'fail' ) );
		} finally {
			$this->release_lock();
		}
	}


	/**
	 * Read current .htaccess contents.
	 *
	 * @return string
	 */
	protected function read_current_htaccess() {
		if ( function_exists( 'get_home_path' ) ) {
			$home = get_home_path();
			if ( is_string( $home ) && '' !== $home ) {
				$path = rtrim( $home, "/\\ \t\n\r\0\x0B" ) . DIRECTORY_SEPARATOR . '.htaccess';
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$buf = @file_get_contents( $path );
				return is_string( $buf ) ? $buf : '';
			}
		}
		if ( defined( 'ABSPATH' ) ) {
			$path = rtrim( ABSPATH, "/\\ \t\n\r\0\x0B" ) . DIRECTORY_SEPARATOR . '.htaccess';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$buf = @file_get_contents( $path );
			return is_string( $buf ) ? $buf : '';
		}
		return '';
	}

	/**
	 * Extract the body checksum from a managed .htaccess header.
	 *
	 * @param string $text Full htaccess text.
	 * @return string Empty if not found.
	 */
	protected function extract_checksum( $text ) {
		if ( preg_match( '/^\s*#\s*STATE\s+sha256:\s*([0-9a-f]{64})\b/mi', (string) $text, $m ) ) {
			return (string) $m[1];
		}
		return '';
	}

	/**
	 * Optional accessor to the DI container (read-only usage suggested).
	 *
	 * @since 1.0.0
	 *
	 * @return Container
	 */
	public function container() {
		return $this->container;
	}

	/**
	 * Compose NFD fragments into a single body without our header,
	 * separating fragments by a blank line.
	 *
	 * @param Fragment[] $fragments Fragments to render.
	 * @param mixed      $context   Context.
	 * @return string
	 */
	protected function compose_body_only( $fragments, $context ) {
		$blocks = array();

		foreach ( $fragments as $fragment ) {
			if ( ! $fragment instanceof Fragment ) {
				continue;
			}
			$rendered = (string) $fragment->render( $context );
			$rendered = str_replace( array( "\r\n", "\r" ), "\n", $rendered );
			$rendered = preg_replace( '/^\s+|\s+$/u', '', $rendered );
			if ( '' !== $rendered ) {
				$blocks[] = $rendered;
			}
		}

		$body = implode( "\n\n", $blocks );
		$body = rtrim( $body, "\n" );

		return $body;
	}


	/**
	 * Lightweight debug log (prefix module).
	 *
	 * @param string $msg Message.
	 * @return void
	 */
	protected function log( $msg ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[htaccess-manager] ' . $msg ); // phpcs:ignore
		}
	}
}
