<?php
/**
 * Public API for the Htaccess module.
 *
 * Provides static helpers other modules can call without needing the
 * DI container: register/unregister fragments and queue an apply.
 *
 * @package NewfoldLabs\WP\Module\Htaccess
 */

namespace NewfoldLabs\WP\Module\Htaccess;

/**
 * Class Api
 *
 * @since 1.0.0
 */
class Api {

	/**
	 * Shared registry instance.
	 *
	 * @var Registry|null
	 */
	protected static $registry = null;

	/**
	 * Manager reference for queueing applies.
	 *
	 * @var Manager|null
	 */
	protected static $manager = null;

	/**
	 * Set the shared registry (called by the module during boot).
	 *
	 * @since 1.0.0
	 *
	 * @param Registry $registry Registry instance.
	 * @return void
	 */
	public static function set_registry( Registry $registry ) {
		self::$registry = $registry;
	}

	/**
	 * Set the manager reference (called by the module during boot).
	 *
	 * @since 1.0.0
	 *
	 * @param Manager $manager Manager instance.
	 * @return void
	 */
	public static function set_manager( Manager $manager ) {
		self::$manager = $manager;
	}

	/**
	 * Get the current registry, creating a local one if none set.
	 *
	 * @since 1.0.0
	 *
	 * @return Registry
	 */
	public static function registry() {
		if ( ! self::$registry instanceof Registry ) {
			self::$registry = new Registry();
		}
		return self::$registry;
	}

	/**
	 * Register (or replace) a fragment and queue an apply.
	 *
	 * @since 1.0.0
	 *
	 * @param Fragment $fragment Fragment to add.
	 * @param bool     $apply    Whether to queue an apply immediately (default true).
	 * @return void
	 */
	public static function register( Fragment $fragment, $apply = true ) {
		$apply = (bool) $apply;

		self::registry()->register( $fragment );

		$reason_label = self::fragment_reason_label( $fragment );

		$changed = false;
		if ( self::$manager instanceof Manager ) {
			$changed = (bool) self::$manager->persist_fragment_state( $fragment );
		} else {
			set_site_transient( Options::get_option_name( 'persist_needed' ), 1, 5 * MINUTE_IN_SECONDS );
			// Also ensure an apply is queued after boot.
			$payload = array(
				'at'     => time(),
				'reason' => 'early:' . $reason_label,
			);
			set_site_transient( Options::get_option_name( 'needs_update' ), $payload, 5 * MINUTE_IN_SECONDS );
		}

		if ( $apply && ( $changed || ! ( self::$manager instanceof Manager ) ) ) {
			self::queue_apply( 'register:' . $reason_label );
		}
	}


	/**
	 * Unregister a fragment by ID and queue an apply.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id    Fragment ID.
	 * @param bool   $apply Whether to queue an apply immediately (default true).
	 * @return void
	 */
	public static function unregister( $id, $apply = true ) {
		$apply = (bool) $apply;
		$id    = (string) $id;

		self::registry()->unregister( $id );

		$changed = false;
		if ( self::$manager instanceof Manager ) {
			$changed = (bool) self::$manager->remove_persisted_fragment( $id );
		} else {
			set_site_transient( Options::get_option_name( 'persist_needed' ), 1, 5 * MINUTE_IN_SECONDS );
			$payload = array(
				'at'     => time(),
				'reason' => 'early:' . $id,
			);
			set_site_transient( Options::get_option_name( 'needs_update' ), $payload, 5 * MINUTE_IN_SECONDS );
		}

		if ( $apply && ( $changed || ! ( self::$manager instanceof Manager ) ) ) {
			self::queue_apply( 'unregister:' . $id );
		}
	}


	/**
	 * Return enabled fragments sorted by priority for a given context.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $context Optional context.
	 * @return Fragment[]
	 */
	public static function enabled_fragments( $context = null ) {
		return self::registry()->enabled_fragments( $context );
	}

	/**
	 * Queue a canonical apply via the manager or a transient fallback.
	 *
	 * @since 1.0.0
	 *
	 * @param string $reason Optional reason label.
	 * @return void
	 */
	public static function queue_apply( $reason = '' ) {
		if ( self::$manager instanceof Manager ) {
			self::$manager->queue_apply( $reason );
			return;
		}

		// Fallback if manager has not been injected yet (early calls).
		$payload = array(
			'at'     => time(),
			'reason' => (string) $reason,
		);
		set_site_transient( Options::get_option_name( 'needs_update' ), $payload, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Get a human-readable label for a fragment (for logging).
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $fragment Fragment instance or ID.
	 * @return string
	 */
	private static function fragment_reason_label( $fragment ) {
		if ( $fragment instanceof Fragment && method_exists( $fragment, 'id' ) ) {
			$id = (string) $fragment->id();
			if ( '' !== $id ) {
				return $id;
			}
		}
		// Fallback to class name.
		return is_object( $fragment ) ? get_class( $fragment ) : 'unknown';
	}
}
