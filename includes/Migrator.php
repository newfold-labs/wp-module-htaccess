<?php
/**
 * Migrator for legacy fragment-markered blocks outside the managed NFD block.
 *
 * Removes legacy "# BEGIN <label> ... # END <label>" blocks so that only the
 * single managed NFD block remains authoritative. Run only during Updater writes.
 *
 * @package NewfoldLabs\WP\Module\Htaccess
 */

namespace NewfoldLabs\WP\Module\Htaccess;

/**
 * Class Migrator
 *
 * @since 1.0.0
 */
class Migrator {

	/**
	 * Remove legacy blocks by labels. Returns transformed text and count removed.
	 *
	 * NOTE: We assume current NFD body does NOT include inner fragment wrappers,
	 * so a global removal of legacy wrappers is safe.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $text           Full .htaccess content (LF-normalized recommended).
	 * @param string[] $legacy_labels  Labels to remove (e.g., "Newfold Skip 404 Handling for Static Files").
	 * @return array { 'text' => string, 'removed' => int }
	 */
	public function remove_legacy_blocks( $text, $legacy_labels ) {
		$buf     = (string) $text;
		$removed = 0;

		if ( empty( $legacy_labels ) ) {
			return array(
				'text'    => $buf,
				'removed' => 0,
			);
		}

		foreach ( $legacy_labels as $label ) {
			$label = (string) $label;
			if ( '' === $label ) {
				continue;
			}
			$begin = '/^\s*#\s*BEGIN\s+' . preg_quote( $label, '/' ) . '\s*$/m';
			$end   = '/^\s*#\s*END\s+' . preg_quote( $label, '/' ) . '\s*$/m';

			// Build a single regex that removes the BEGIN..END block including surrounding whitespace.
			$pattern = '/^\s*#\s*BEGIN\s+' . preg_quote( $label, '/' ) . '\s*$.*?^\s*#\s*END\s+' . preg_quote( $label, '/' ) . '\s*$/ms';

			$before = $buf;
			$buf    = preg_replace( $pattern, '', $buf, -1, $count );
			if ( null === $buf ) {
				// On regex failure, revert and skip this label.
				$buf = $before;
				continue;
			}
			$removed += (int) $count;
		}

		// Collapse extra blank lines and ensure single trailing newline.
		$buf = preg_replace( "/\n{3,}/", "\n\n", $buf );
		$buf = rtrim( $buf, "\n" ) . "\n";

		return array(
			'text'    => $buf,
			'removed' => $removed,
		);
	}
}
