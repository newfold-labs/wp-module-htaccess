<?php
namespace NewfoldLabs\WP\Module\Htaccess;

/**
 * Small text utilities for stable whitespace handling.
 *
 * @since 1.0.0
 */
final class Text {

	/**
	 * Normalize line endings to LF and optionally trim trailing newlines.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Input text.
	 * @param bool   $trim_trailing_newlines When true, trims trailing "\n".
	 * @return string
	 */
	public static function normalize_lf( $text, $trim_trailing_newlines = true ) {
		$out = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		return $trim_trailing_newlines ? rtrim( $out, "\n" ) : $out;
	}

	/**
	 * Trim leading/trailing whitespace-only lines (preserves inner spacing).
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Input text.
	 * @return string
	 */
	public static function trim_surrounding_blank_lines( $text ) {
		$out = preg_replace( '/^\s+|\s+$/u', '', (string) $text );
		return ( null === $out ) ? '' : $out;
	}

	/**
	 * Ensure exactly one trailing newline.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Input text.
	 * @return string
	 */
	public static function ensure_single_trailing_newline( $text ) {
		return rtrim( (string) $text, "\r\n" ) . "\n";
	}

	/**
	 * Collapse sequences of 3+ blank lines to 2.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text LF-normalized text.
	 * @return string
	 */
	public static function collapse_excess_blanks( $text ) {
		return preg_replace( "/\n{3,}/", "\n\n", (string) $text );
	}
}
