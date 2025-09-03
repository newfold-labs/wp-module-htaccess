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
 * Responsibilities:
 * - Tracks and composes NFD-managed fragments (in-memory each request).
 * - Persists fragment blocks and the composed body to avoid recomposition churn.
 * - Schedules a single, debounced write at shutdown (safe contexts only).
 * - Reconciles saved state vs. on-disk block and queues a single apply if drift.
 *
 * Notes:
 * - Writes only occur once at shutdown and only if content changes (Updater no-ops).
 * - Never writes a header-only/blank managed block.
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
	 * Cron service (scheduling and handler).
	 *
	 * @var Cron
	 */
	protected $cron;

	/**
	 * Whether or not an apply is queued (dirty state).
	 *
	 * @var bool
	 */
	protected $dirty = false;

	/**
	 * Persisted state option key.
	 *
	 * @var string
	 */
	protected $state_option_key = 'nfd_htaccess_saved_state';

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
		$this->cron      = new Cron();

		// Expose to static API for other modules to use without the container.
		Api::set_registry( $this->registry );
		Api::set_manager( $this );

		// Reconcile persisted block vs. disk at init (queues if drift found).
		// Runs late so that any runtime registrations on init have happened.
		add_action( 'admin_init', array( $this, 'reconcile_saved_block' ), 1 );

		// Queue on common events that affect rewrite rules or fragments.
		add_action( 'switch_theme', array( $this, 'queue_apply' ) );
		add_action( 'activated_plugin', array( $this, 'queue_apply' ) );
		add_action( 'deactivated_plugin', array( $this, 'queue_apply' ) );
		add_action( 'permalink_structure_changed', array( $this, 'queue_apply' ) );

		// Option watcher (e.g., permalink, home/siteurl, module toggles).
		add_action( 'updated_option', array( $this, 'maybe_queue_on_option' ), 10, 3 );

		// Single debounced write at safe boundaries (admin/cron/CLI only).
		add_action( 'shutdown', array( $this, 'maybe_apply_on_shutdown' ), 2 );

		WP_CLI::add_command( 'newfold htaccess', CLI::class );

		// Register cron scheduling and handler.
		$this->cron->register();
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
	 * @param string $option Option name.
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
	 * @since 1.0.0
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
	 * @since 1.0.0
	 *
	 * @return bool True if lock acquired, false if already locked.
	 */
	protected function acquire_lock() {
		return (bool) set_transient( 'nfd_htaccess_write_lock', 1, 30 ); // 30s
	}

	/**
	 * Release the write lock.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function release_lock() {
		delete_transient( 'nfd_htaccess_write_lock' );
	}

	/**
	 * On init, ensure the persisted NFD block equals the on-disk managed block.
	 *
	 * Fast path: compare checksum from saved state vs. current header.
	 * If different or missing, queue a single apply for shutdown (single write).
	 *
	 * This method NEVER writes directly; it only queues an apply.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function reconcile_saved_block() {
		// If an apply is already queued for this request, skip to avoid double writes.
		if ( $this->dirty || get_site_transient( 'nfd_htaccess_needs_update' ) ) {
			return;
		}

		$saved = $this->load_saved_state();
		if ( empty( $saved['checksum'] ) ) {
			return;
		}

		$current = $this->read_current_htaccess();
		if ( '' === $current ) {
			// Missing/unreadable file; queue one apply.
			$this->queue_apply( 'reconcile:missing-file' );
			return;
		}

		// Ask Updater to compute the CURRENT on-disk body hash in the managed block.
		$current_hash = '';
		if ( $this->updater instanceof Updater ) {
			$current_hash = (string) $this->updater->get_current_body_hash();
		}

		if ( $current_hash !== (string) $saved['checksum'] ) {
			// Drift detected; queue one apply.
			$this->queue_apply( 'reconcile:drift' );
		}
	}

	/**
	 * Determine if there are any enabled fragments in the current request.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $context Context snapshot.
	 * @return bool
	 */
	protected function has_active_fragments( $context ) {
		$list = $this->registry->enabled_fragments( $context );
		return ( is_array( $list ) && ! empty( $list ) );
	}

	/**
	 * Compose, validate, and merge ONLY the NFD-managed block into .htaccess.
	 *
	 * - Gathers enabled fragments from the registry (for this request).
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

		if ( ! $this->acquire_lock() ) {
			return; // another request is writing
		}
		try {
			// 1) Build context and prep metadata.
			$context = Context::from_wp( array() );
			$host    = $context->host();
			$version = '1.0.0';

			// 2) Collect enabled fragments and drop WordPress-related ones.
			// Prefer the persisted body if present; this lets rules accumulate across requests.
			$saved = $this->load_saved_state();
			if ( is_array( $saved ) && ! empty( $saved['body'] ) ) {
				$body = (string) $saved['body'];
			} else {
				// Fallback: compose from the currently enabled fragments (first boot, etc.).
				$all       = $this->registry->enabled_fragments( $context );
				$fragments = array();
				$seen_excl = array();

				foreach ( $all as $f ) {
					$id = method_exists( $f, 'id' ) ? (string) $f->id() : '';

					// Skip anything WordPress-y: we do NOT manage core rules here.
					if ( '' !== $id && ( 'WordPress.core' === $id || false !== strpos( $id, 'WordPress' ) ) ) {
						continue;
					}

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

				$body = $this->compose_body_only( $fragments, $context );
			}

			// 4) Validate & remediate (lightweight: flags, handlers, BEGIN/END balance within body).
			// We pass an empty exclusives list here because exclusivity was already enforced above.
			$is_valid = $this->validator->is_valid( $body, array() );

			if ( ! $is_valid ) {
				$body = $this->validator->remediate( $body );

				// Re-check after remediation; if still invalid, abort to avoid breaking .htaccess.
				if ( ! $this->validator->is_valid( $body, array() ) ) {
					return;
				}
			}

			// 5) Merge into the managed marker block with checksum; Updater no-ops if unchanged.
			$ok = $this->updater->apply_managed_block( $body, $host, $version );
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Read current .htaccess contents (best-effort; no error silencing).
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function read_current_htaccess() {
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

		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$buf = file_get_contents( $path );
		return is_string( $buf ) ? $buf : '';
	}

	/**
	 * Extract the body checksum from a managed .htaccess header.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * Persist (or update) a single fragment's rendered block into saved state.
	 *
	 * Renders the fragment in the current context, stores its body and priority
	 * keyed by fragment ID, then recomposes/saves the full persisted body + checksum.
	 * Idempotent: returns false if nothing effectively changed.
	 *
	 * @since 1.0.0
	 *
	 * @param Fragment $fragment Fragment instance.
	 * @return bool True if persisted composed body changed; false if no-op.
	 */
	public function persist_fragment_state( Fragment $fragment ) {
		if ( ! $fragment instanceof Fragment ) {
			return false;
		}

		$context = class_exists( __NAMESPACE__ . '\Context' ) ? Context::from_wp( array() ) : null;
		$host    = $context ? $context->host() : '';
		$version = '1.0.0';

		// Render this fragment alone (normalized, trimmed).
		$block = (string) $fragment->render( $context );
		$block = str_replace( array( "\r\n", "\r" ), "\n", $block );
		$block = preg_replace( '/^\s+|\s+$/u', '', $block );

		$id       = method_exists( $fragment, 'id' ) ? (string) $fragment->id() : get_class( $fragment );
		$priority = (int) ( method_exists( $fragment, 'priority' ) ? $fragment->priority() : 0 );

		$state = $this->load_saved_state();
		if ( empty( $state['blocks'] ) || ! is_array( $state['blocks'] ) ) {
			$state['blocks'] = array();
		}

		$prev = isset( $state['blocks'][ $id ] ) ? $state['blocks'][ $id ] : null;

		// If new render is empty but we already have a non-empty stored block,
		// DO NOT overwrite the stored body. Update only the priority metadata.
		$next_body = (string) $block;

		// If the stored block is identical (body + priority), it's a no-op.
		if ( is_array( $prev )
			&& array_key_exists( 'body', $prev )
			&& array_key_exists( 'priority', $prev )
			&& (string) $prev['body'] === $next_body
			&& (int) $prev['priority'] === $priority
		) {
			return false;
		}

		// Update this fragment's entry.
		$state['blocks'][ $id ] = array(
			'body'     => $next_body,
			'priority' => $priority,
		);

		$old_body = isset( $state['body'] ) ? (string) $state['body'] : '';
		$new_body = $this->compose_from_saved_blocks( $state );

		// If recomposed body is unchanged, no persistence or apply needed.
		if ( (string) $new_body === (string) $old_body ) {
			return false;
		}

		// Validate/remediate non-empty bodies before saving.
		if ( '' !== $new_body && ! $this->validator->is_valid( $new_body, array() ) ) {
			$new_body = $this->validator->remediate( $new_body );
			if ( ! $this->validator->is_valid( $new_body, array() ) ) {
				return false;
			}
		}

		$this->save_state_full( $state, $new_body, $host, $version );
		return true;
	}

	/**
	 * Remove a fragment's block from saved state by ID and recompute persisted body.
	 *
	 * Idempotent: returns false if nothing effectively changed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Fragment ID.
	 * @return bool True if persisted composed body changed; false if no-op.
	 */
	public function remove_persisted_fragment( $id ) {
		$state = $this->load_saved_state();

		if ( empty( $state['blocks'] ) || ! is_array( $state['blocks'] ) ) {
			return false;
		}

		$id = (string) $id;
		if ( ! isset( $state['blocks'][ $id ] ) ) {
			return false;
		}

		unset( $state['blocks'][ $id ] );

		$context = class_exists( __NAMESPACE__ . '\Context' ) ? Context::from_wp( array() ) : null;
		$host    = $context ? $context->host() : '';
		$version = '1.0.0';

		$old_body = isset( $state['body'] ) ? (string) $state['body'] : '';
		$new_body = $this->compose_from_saved_blocks( $state );

		if ( (string) $new_body === (string) $old_body ) {
			return false;
		}

		if ( '' !== $new_body && ! $this->validator->is_valid( $new_body, array() ) ) {
			$new_body = $this->validator->remediate( $new_body );
			if ( ! $this->validator->is_valid( $new_body, array() ) ) {
				return false;
			}
		}

		$this->save_state_full( $state, $new_body, $host, $version );
		return true;
	}

	/**
	 * Compose NFD body from saved state blocks sorted by priority then ID.
	 *
	 * @since 1.0.0
	 *
	 * @param array $state Saved state containing 'blocks'.
	 * @return string Composed body (no trailing newline).
	 */
	protected function compose_from_saved_blocks( $state ) {
		$blocks = array();

		if ( ! empty( $state['blocks'] ) && is_array( $state['blocks'] ) ) {
			// Sort by priority asc, then ID asc for stability.
			$items = $state['blocks'];
			uksort(
				$items,
				function ( $a, $b ) use ( $items ) {
					$pa = isset( $items[ $a ]['priority'] ) ? (int) $items[ $a ]['priority'] : 0;
					$pb = isset( $items[ $b ]['priority'] ) ? (int) $items[ $b ]['priority'] : 0;
					if ( $pa === $pb ) {
						return strcmp( (string) $a, (string) $b );
					}
					return ( $pa < $pb ) ? -1 : 1;
				}
			);

			foreach ( $items as $meta ) {
				$body = isset( $meta['body'] ) ? (string) $meta['body'] : '';
				$body = str_replace( array( "\r\n", "\r" ), "\n", $body );
				$body = preg_replace( '/^\s+|\s+$/u', '', $body );
				if ( '' !== $body ) {
					$blocks[] = $body;
				}
			}
		}

		$out = implode( "\n\n", $blocks );
		return rtrim( $out, "\n" );
	}

	/**
	 * Load persisted NFD state (accumulated fragment blocks and composed body).
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	protected function load_saved_state() {
		$payload = is_multisite() && function_exists( 'get_site_option' )
			? get_site_option( $this->state_option_key, array() )
			: get_option( $this->state_option_key, array() );

		return is_array( $payload ) ? $payload : array();
	}

	/**
	 * Save full persisted state (blocks map + composed body + checksum + metadata).
	 *
	 * Never clobbers a non-empty existing body with an empty one.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $state   State with 'blocks' map.
	 * @param string $body    Composed body (no trailing newline).
	 * @param string $host    Host label.
	 * @param string $version Version string.
	 * @return void
	 */
	protected function save_state_full( $state, $body, $host, $version ) {
		$payload = is_array( $state ) ? $state : array();

		// If new body is empty but we already have a non-empty one, keep the existing body/checksum.
			$payload['body']     = (string) $body;
			$payload['checksum'] = hash( 'sha256', $this->normalize_lf( (string) $body ) );

		$payload['host']      = (string) $host;
		$payload['version']   = (string) $version;
		$payload['updatedAt'] = time();

		if ( is_multisite() && function_exists( 'update_site_option' ) ) {
			update_site_option( $this->state_option_key, $payload );
		} else {
			update_option( $this->state_option_key, $payload );
		}
	}

	/**
	 * Normalize to LF without trailing newlines (for stable checksums).
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Input.
	 * @return string
	 */
	protected function normalize_lf( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		return rtrim( $text, "\n" );
	}

	/**
	 * Compute the sha256 checksum of the BODY currently inside the NFD managed block,
	 * ignoring the in-block header. Returns '' ONLY if the block is missing/unreadable.
	 *
	 * Robust behavior:
	 * - Prefer extract_from_markers(); if unavailable or empty, fall back to manual parse.
	 * - Anchor on "# STATE sha256:" to find the body start; otherwise skip leading "#" lines
	 *   and a single blank separator.
	 *
	 * @since 1.0.0
	 *
	 * @return string sha256 checksum of body, or '' if no block found.
	 */
	protected function compute_current_block_checksum() {
		// Resolve .htaccess path.
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
		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}

		// Try WordPress helpers first.
		$this->ensure_wp_file_helpers();
		$lines = array();
		if ( function_exists( 'extract_from_markers' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
			$lines = extract_from_markers( $path, 'NFD Htaccess' ); // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
			if ( ! is_array( $lines ) ) {
				$lines = array();
			}
		}

		// Fallback: manual parse if helpers failed to find the block.
		if ( empty( $lines ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$buf = file_get_contents( $path );
			if ( ! is_string( $buf ) || '' === $buf ) {
				return '';
			}
			$buf = str_replace( array( "\r\n", "\r" ), "\n", $buf );

			$begin_re = '/^\s*#\s*BEGIN\s+NFD Htaccess\s*$/mi';
			$end_re   = '/^\s*#\s*END\s+NFD Htaccess\s*$/mi';

			if ( ! preg_match( $begin_re, $buf, $mb, PREG_OFFSET_CAPTURE ) ) {
				return '';
			}
			if ( ! preg_match( $end_re, $buf, $me, PREG_OFFSET_CAPTURE ) ) {
				return '';
			}
			$begin_pos = $mb[0][1] + strlen( $mb[0][0] );
			$end_pos   = $me[0][1];

			if ( $end_pos <= $begin_pos ) {
				return '';
			}

			$inner = substr( $buf, $begin_pos, $end_pos - $begin_pos );
			$inner = ltrim( $inner, "\n" );
			$lines = explode( "\n", $inner );
		}

		// If still nothing, no block present.
		if ( empty( $lines ) ) {
			return '';
		}

		// Find "# STATE sha256:" header line. If missing, fall back to skipping header comments.
		$state_index = -1;
		foreach ( $lines as $i => $line ) {
			if ( preg_match( '/^\s*#\s*STATE\s+sha256:\s*[0-9a-f]{64}\b/i', $line ) ) {
				$state_index = $i;
				break;
			}
		}

		$start = ( -1 === $state_index ) ? 0 : $state_index + 1;
		if ( -1 === $state_index ) {
			// Skip leading comment header lines if no STATE line.
			$max = count( $lines );
			while ( $start < $max && preg_match( '/^\s*#/', $lines[ $start ] ) ) {
				++$start;
			}
		}
		// Skip a single blank separator if present.
		if ( $start < count( $lines ) && '' === trim( $lines[ $start ] ) ) {
			++$start;
		}

		$body_lines = array_slice( $lines, $start );
		$body       = implode( "\n", $body_lines );
		$body       = str_replace( array( "\r\n", "\r" ), "\n", $body );
		$body       = rtrim( $body, "\n" );

		// Return hash of the body (empty body => e3b0c442...).
		return hash( 'sha256', $body );
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
}
