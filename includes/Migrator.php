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
		// Normalize to LF, remove BOM, normalize NBSPs to spaces.
		$buf = (string) $text;
		$buf = str_replace( array( "\r\n", "\r" ), "\n", $buf );
		$buf = ltrim( $buf, "\xEF\xBB\xBF" );          // UTF-8 BOM
		$buf = str_replace( "\xC2\xA0", ' ', $buf );    // NBSP -> space

		// Nothing to do?
		if ( '' === $buf || empty( $legacy_labels ) || ! is_array( $legacy_labels ) ) {
			return array(
				'text'    => $this->postprocess( $buf ),
				'removed' => 0,
			);
		}

		// De-dupe/trim and never target our managed marker.
		$labels = array();
		foreach ( $legacy_labels as $label ) {
			$l = trim( (string) $label );
			if ( '' === $l ) {
				continue;
			}
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

		foreach ( array_keys( $labels ) as $label ) {
			$quoted = preg_quote( $label, '~' );

			/**
			 * More-forgiving marker remover:
			 * - ^[ \t]*#\s*BEGIN <label>\s*$   (BEGIN line, multiline)
			 * - (.*?)                           (lazy body, including newlines)
			 * - ^[ \t]*#\s*END <label>\s*$     (END line, multiline)
			 *
			 * Flags:
			 * - m: ^ and $ are per-line
			 * - s: dot also matches \n
			 * - u: unicode-safe
			 */
			$pattern = '~^[ \t]*#\s*BEGIN\s+' . $quoted . '\s*$'
				. '(.*?)'
				. '^[ \t]*#\s*END\s+' . $quoted . '\s*$~msu';

			$buf = preg_replace( $pattern, '', $buf, -1, $count );

			if ( null === $buf ) {
				// Regex engine error; skip this label safely.
				$buf = (string) $text;
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
