<?php

namespace NewfoldLabs\WP\Module\Htaccess;

/**
 * Tests for Validator class.
 *
 * @covers \NewfoldLabs\WP\Module\Htaccess\Validator
 */
class ValidatorWPUnitTest extends \lucatume\WPBrowser\TestCase\WPTestCase {

	/**
	 * Valid content passes is_valid.
	 *
	 * @return void
	 */
	public function test_is_valid_accepts_balanced_begin_end() {
		$validator = new Validator();
		$text      = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteRule x 1 [L]\n</IfModule>\n# END WordPress";
		$this->assertTrue( $validator->is_valid( $text, array() ) );
		$this->assertSame( array(), $validator->get_errors() );
	}

	/**
	 * Unclosed BEGIN is reported as error.
	 *
	 * @return void
	 */
	public function test_is_valid_reports_unclosed_begin() {
		$validator = new Validator();
		$text      = "# BEGIN Foo\n# END Bar";
		$this->assertFalse( $validator->is_valid( $text, array() ) );
		$errors = $validator->get_errors();
		$this->assertNotEmpty( $errors );
	}

	/**
	 * Unbalanced IfModule is reported.
	 *
	 * @return void
	 */
	public function test_is_valid_reports_unbalanced_ifmodule() {
		$validator = new Validator();
		$text      = "<IfModule mod_rewrite.c>\nRewriteRule x 1 [L]";
		$this->assertFalse( $validator->is_valid( $text, array() ) );
		$this->assertNotEmpty( $validator->get_errors() );
	}

	/**
	 * Unbalanced rewrite flags are reported.
	 *
	 * @return void
	 */
	public function test_is_valid_reports_unbalanced_rewrite_flags() {
		$validator = new Validator();
		$text      = "RewriteRule x 1 [L\n";
		$this->assertFalse( $validator->is_valid( $text, array() ) );
		$this->assertNotEmpty( $validator->get_errors() );
	}

	/**
	 * Remediate returns string and removes forbidden handler lines.
	 *
	 * @return void
	 */
	public function test_remediate_returns_string() {
		$validator = new Validator();
		$input     = "# BEGIN X\nAddHandler application/x-httpd-php .php\n# END X";
		$output    = $validator->remediate( $input );
		$this->assertIsString( $output );
		$this->assertStringNotContainsString( 'AddHandler', $output );
	}
}
