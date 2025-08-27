<?php
/**
 * Registry for Htaccess fragments.
 *
 * @package NewfoldLabs\WP\Module\Htaccess
 */

namespace NewfoldLabs\WP\Module\Htaccess;

/**
 * Holds fragment instances registered by other modules.
 *
 * @since 1.0.0
 */
class Registry {

	/**
	 * Registered fragments keyed by fragment ID.
	 *
	 * @var array<string, Fragment>
	 */
	protected $fragments = array();

	/**
	 * Register (or replace) a fragment.
	 *
	 * Idempotent: a fragment with the same ID will be replaced.
	 *
	 * @since 1.0.0
	 *
	 * @param Fragment $fragment Fragment instance to register.
	 * @return void
	 */
	public function register( Fragment $fragment ) {
		$this->fragments[ $fragment->id() ] = $fragment;
	}

	/**
	 * Unregister a fragment by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Fragment ID.
	 * @return void
	 */
	public function unregister( $id ) {
		if ( isset( $this->fragments[ $id ] ) ) {
			unset( $this->fragments[ $id ] );
		}
	}

	/**
	 * Check if a fragment exists by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Fragment ID.
	 * @return bool
	 */
	public function has( $id ) {
		return isset( $this->fragments[ $id ] );
	}

	/**
	 * Get a fragment by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Fragment ID.
	 * @return Fragment|null
	 */
	public function get( $id ) {
		return isset( $this->fragments[ $id ] ) ? $this->fragments[ $id ] : null;
	}

	/**
	 * Return all registered fragments (unsorted).
	 *
	 * @since 1.0.0
	 *
	 * @return Fragment[]
	 */
	public function all() {
		return array_values( $this->fragments );
	}

	/**
	 * Return only fragments that are enabled for the given context,
	 * sorted by ascending priority (lower number renders earlier).
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $context Optional context object passed to fragments.
	 * @return Fragment[]
	 */
	public function enabled_fragments( $context = null ) {
		$enabled = array();

		foreach ( $this->fragments as $fragment ) {
			// Guard: fragment must implement the expected API.
			if ( ! method_exists( $fragment, 'is_enabled' ) || ! method_exists( $fragment, 'priority' ) ) {
				continue;
			}

			if ( true === $fragment->is_enabled( $context ) ) {
				$enabled[] = $fragment;
			}
		}

		usort(
			$enabled,
			function ( $a, $b ) {
				$pa = (int) ( method_exists( $a, 'priority' ) ? $a->priority() : 0 );
				$pb = (int) ( method_exists( $b, 'priority' ) ? $b->priority() : 0 );

				if ( $pa === $pb ) {
					// Stable-ish tie-breaker by ID to avoid flapping output.
					$ia = method_exists( $a, 'id' ) ? (string) $a->id() : '';
					$ib = method_exists( $b, 'id' ) ? (string) $b->id() : '';
					return strcmp( $ia, $ib );
				}

				return ( $pa < $pb ) ? -1 : 1;
			}
		);

		return $enabled;
	}

	/**
	 * Clear all registered fragments.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function clear() {
		$this->fragments = array();
	}
}
