<?php
/**
 * Fragment interface for Htaccess module.
 *
 * Each fragment represents a self-contained block of .htaccess rules
 * that can be registered by other modules and composed by the manager.
 *
 * @package NewfoldLabs\WP\Module\Htaccess
 */

namespace NewfoldLabs\WP\Module\Htaccess;

/**
 * Interface Fragment
 *
 * Implement this in other modules to contribute .htaccess rules.
 *
 * @since 1.0.0
 */
interface Fragment {

	/**
	 * Priority constants (lower renders earlier).
	 *
	 * @since 1.0.0
	 */
	const PRIORITY_PRE_WP   = 100;
	const PRIORITY_WP       = 200;
	const PRIORITY_POST_WP  = 300;
	const PRIORITY_SECURITY = 400;
	const PRIORITY_LAST     = 900;

	/**
	 * Unique fragment ID (e.g., "epc.skip-static-404").
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Render order. Lower numbers render first.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function priority();

	/**
	 * Whether only a single instance of this fragment
	 * may exist in the final output (e.g., WordPress canonical block).
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function exclusive();

	/**
	 * Whether this fragment should be included for the given context.
	 *
	 * Example checks: plugin active, module setting enabled, multisite mode, etc.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $context Optional context object or array.
	 * @return bool
	 */
	public function is_enabled( $context );

	/**
	 * Return the exact .htaccess text for this fragment.
	 *
	 * Do not include a trailing newline; the composer will handle spacing.
	 * Include BEGIN/END comment markers inside your block for clarity.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $context Optional context object or array.
	 * @return string
	 */
	public function render( $context );
}
