<?php
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
	 * @param string   $text          Full .htaccess content (any line endings).
	 * @param string[] $legacy_labels Labels to remove (e.g., "Newfold Skip 404 Handling for Static Files").
	 * @return array { 'text' => string, 'removed' => int }
	 */
	public function remove_legacy_blocks( $text, $legacy_labels ) {
		$buf = (string) $text;

		// Normalize to LF once so regex anchors behave predictably.
		$buf = str_replace( array( "\r\n", "\r" ), "\n", $buf );

		// Nothing to do?
		if ( '' === $buf || empty( $legacy_labels ) || ! is_array( $legacy_labels ) ) {
			return array(
				'text'    => $this->postprocess( $buf ),
				'removed' => 0,
			);
		}

		// De-dupe, trim, drop empties.
		$labels = array();
		foreach ( $legacy_labels as $label ) {
			$l = trim( (string) $label );
			if ( '' === $l ) {
				continue;
			}
			// Defensive: never remove our managed marker block.
			if ( 0 === strcasecmp( $l, Config::marker() ) ) {
				continue;
			}
			$labels[ $l ] = true;
		}
		if ( empty( $labels ) ) {
			return array(
				'text'    => $this->postprocess( $buf ),
				'removed' => 0,
			);
		}

		$removed = 0;

		// Remove each label’s BEGIN..END block(s), if present.
		foreach ( array_keys( $labels ) as $label ) {
			$quoted = preg_quote( $label, '/' );

			/**
			 * Pattern notes:
			 * - ^\s*#\s*BEGIN <label>$  anchored in /m
			 * - [\s\S]*? matches lazily across lines (like DOTALL) without relying solely on 's'
			 * - Atomic group (?>...) limits catastrophic backtracking on large files
			 * - ^\s*#\s*END <label>$ anchored in /m
			 */
			$pattern = '/^\s*#\s*BEGIN\s+' . $quoted . '\s*$'         // BEGIN line
					. '(?>[\s\S]*?)'                                 // non-greedy body (atomic)
					. '^\s*#\s*END\s+' . $quoted . '\s*$'            // END line
					. '/m';

			$before = $buf;
			$buf    = preg_replace( $pattern, '', $buf, -1, $count );

			if ( null === $buf ) {
				// On regex error revert and skip this label.
				$buf = $before;
				continue;
			}
			$removed += (int) $count;
		}

		return array(
			'text'    => $this->postprocess( $buf ),
			'removed' => $removed,
		);
	}

	/**
	 * Collapse excessive blanks and ensure single trailing newline.
	 *
	 * @param string $buf LF-normalized text.
	 * @return string
	 */
	private function postprocess( $buf ) {
		$buf = Text::collapse_excess_blanks( (string) $buf );
		return Text::ensure_single_trailing_newline( $buf );
	}
}
