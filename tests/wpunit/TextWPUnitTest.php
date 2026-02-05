<?php

namespace NewfoldLabs\WP\Module\Htaccess;

/**
 * Tests for Text utility class.
 *
 * @covers \NewfoldLabs\WP\Module\Htaccess\Text
 */
class TextWPUnitTest extends \lucatume\WPBrowser\TestCase\WPTestCase {

	/**
	 * Normalize_lf converts CRLF and CR to LF.
	 *
	 * @return void
	 */
	public function test_normalize_lf_converts_line_endings() {
		$this->assertSame( "a\nb\nc", Text::normalize_lf( "a\r\nb\rc", true ) );
		$this->assertSame( "a\nb\n", Text::normalize_lf( "a\r\nb\n", false ) );
	}

	/**
	 * Trim_surrounding_blank_lines removes leading and trailing blank lines.
	 *
	 * @return void
	 */
	public function test_trim_surrounding_blank_lines() {
		$this->assertSame( "x\n\ny", Text::trim_surrounding_blank_lines( "\n\nx\n\ny\n\n" ) );
	}

	/**
	 * Ensure_single_trailing_newline adds or normalizes trailing newline.
	 *
	 * @return void
	 */
	public function test_ensure_single_trailing_newline() {
		$this->assertSame( "a\n", Text::ensure_single_trailing_newline( 'a' ) );
		$this->assertSame( "a\n", Text::ensure_single_trailing_newline( "a\n\n" ) );
	}

	/**
	 * Collapse_excess_blanks reduces 3+ newlines to 2.
	 *
	 * @return void
	 */
	public function test_collapse_excess_blanks() {
		$this->assertSame( "a\n\nb", Text::collapse_excess_blanks( "a\n\n\n\nb" ) );
	}

	/**
	 * Strip_inner_markers_from_body removes BEGIN/END marker lines.
	 *
	 * @return void
	 */
	public function test_strip_inner_markers_from_body() {
		$input = "# BEGIN Foo\nRewriteRule ^x 1 [L]\n# END Foo";
		$this->assertSame( 'RewriteRule ^x 1 [L]', Text::strip_inner_markers_from_body( $input ) );
	}

	/**
	 * Extract_from_markers_raw returns lines between BEGIN and END marker.
	 *
	 * @return void
	 */
	public function test_extract_from_markers_raw() {
		$buf   = "before\n# BEGIN TestMarker\nline1\nline2\n# END TestMarker\nafter";
		$lines = Text::extract_from_markers_raw( $buf, 'TestMarker' );
		$this->assertSame( array( 'line1', 'line2' ), $lines );
	}

	/**
	 * Extract_from_markers_raw returns empty array when marker missing.
	 *
	 * @return void
	 */
	public function test_extract_from_markers_raw_returns_empty_when_marker_missing() {
		$this->assertSame( array(), Text::extract_from_markers_raw( 'no marker here', 'Missing' ) );
	}

	/**
	 * Extract_from_markers_text returns string content between markers.
	 *
	 * @return void
	 */
	public function test_extract_from_markers_text() {
		$buf = "# BEGIN M\nhello\n# END M";
		$this->assertSame( 'hello', Text::extract_from_markers_text( $buf, 'M' ) );
	}

	/**
	 * Canonicalize_managed_body_for_hash strips managed header lines.
	 *
	 * @return void
	 */
	public function test_canonicalize_managed_body_for_hash_strips_headers() {
		$body  = "# Managed by NFD\n# STATE sha256: abc\n\nRewriteRule x 1 [L]";
		$canon = Text::canonicalize_managed_body_for_hash( $body );
		$this->assertSame( 'RewriteRule x 1 [L]', $canon );
	}
}
