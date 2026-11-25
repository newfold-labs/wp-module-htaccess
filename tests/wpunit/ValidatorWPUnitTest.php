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
	 * Wordfence opens its block with "# Wordfence WAF" and no BEGIN.
	 *
	 * @return void
	 */
	public function test_is_valid_accepts_wordfence_waf_block() {
		$validator = new Validator();
		$text      = "# BEGIN WordPress\n# END WordPress\n"
			. "# BEGIN NFD Htaccess\n# BEGIN Newfold Browser Cache\nExpiresActive On\n# END Newfold Browser Cache\n# END NFD Htaccess\n"
			. "# Wordfence WAF\n<IfModule LiteSpeed>\nphp_value auto_prepend_file '/home/x/public_html/wordfence-waf.php'\n</IfModule>\n"
			. "<Files \".user.ini\">\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n</Files>\n\n# END Wordfence WAF\n";
		$this->assertTrue( $validator->is_valid( $text, array() ) );
		$this->assertSame( array(), $validator->get_errors() );
	}

	/**
	 * An END with no BEGIN and no earlier bare opener is still an error.
	 *
	 * @return void
	 */
	public function test_is_valid_reports_orphan_end() {
		$validator = new Validator();
		$this->assertFalse( $validator->is_valid( "# BEGIN WordPress\n# END WordPress\n# END Wordfence WAF\n", array() ) );
		$this->assertNotEmpty( $validator->get_errors() );
	}

	/**
	 * A bare opener closes one block only, and only one that comes after it.
	 *
	 * @return void
	 */
	public function test_bare_opener_is_consumed_once_and_must_come_first() {
		$validator = new Validator();
		$this->assertFalse( $validator->is_valid( "# Foo\n# END Foo\n# END Foo\n", array() ) );
		$this->assertFalse( $validator->is_valid( "# END Foo\n# Foo\n", array() ) );
	}

	/**
	 * WP Rocket and Really Simple SSL add a version after the label on BEGIN only.
	 *
	 * @return void
	 */
	public function test_is_valid_accepts_versioned_begin_labels() {
		$validator = new Validator();
		$this->assertTrue( $validator->is_valid( "# BEGIN WP Rocket v3.16.2\nAddDefaultCharset UTF-8\n# END WP Rocket\n", array() ) );
		$this->assertTrue( $validator->is_valid( "# BEGIN rlrssslReallySimpleSSL rsssl_version[5.0]\nRewriteEngine on\n# END rlrssslReallySimpleSSL\n", array() ) );
		$this->assertFalse( $validator->is_valid( "# BEGIN WP Rocketeer\n# END WP Rocket\n", array() ) );
	}

	/**
	 * A bare-opener block may close while a BEGIN block is still open.
	 *
	 * @return void
	 */
	public function test_bare_opener_block_can_cross_a_begin_block() {
		$validator = new Validator();
		$this->assertTrue( $validator->is_valid( "# Foo\n# BEGIN A\n# END Foo\n# END A\n", array() ) );
	}

	/**
	 * Accepted relaxation: a comment equal to a label pairs with a stray END.
	 *
	 * @return void
	 */
	public function test_comment_matching_a_label_pairs_with_an_extra_end() {
		$validator = new Validator();
		$this->assertTrue( $validator->is_valid( "# X\n# BEGIN X\n# END X\n# END X\n", array() ) );
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
