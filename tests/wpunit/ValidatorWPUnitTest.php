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
	 * A Wordfence END with no opener, or a second END, is still an error.
	 *
	 * @return void
	 */
	public function test_is_valid_reports_unpaired_wordfence_end() {
		$validator = new Validator();
		$this->assertFalse( $validator->is_valid( "# BEGIN WordPress\n# END WordPress\n# END Wordfence WAF\n", array() ) );
		$this->assertFalse( $validator->is_valid( "# Wordfence WAF\n# END Wordfence WAF\n# END Wordfence WAF\n", array() ) );
	}

	/**
	 * Known openers never make a previously valid file invalid.
	 *
	 * @return void
	 */
	public function test_known_opener_does_not_affect_begin_blocks() {
		$validator = new Validator();
		$this->assertTrue( $validator->is_valid( "# Wordfence WAF\nphp_value x y\n", array() ) );
		$this->assertTrue( $validator->is_valid( "# BEGIN A\n# Wordfence WAF\n# END A\n# END Wordfence WAF\n", array() ) );
		$this->assertTrue( $validator->is_valid( "# BEGIN A\n# Wordfence WAF\n# END Wordfence WAF\n# END A\n", array() ) );
	}

	/**
	 * Repeated known blocks each pair with their own END.
	 *
	 * @return void
	 */
	public function test_is_valid_accepts_repeated_wordfence_blocks() {
		$validator = new Validator();
		$block     = "# Wordfence WAF\nphp_value x y\n# END Wordfence WAF\n";
		$this->assertTrue( $validator->is_valid( $block . "# BEGIN WordPress\n# END WordPress\n" . $block, array() ) );
		$this->assertTrue( $validator->is_valid( "# Wordfence WAF\n# Wordfence WAF\n# END Wordfence WAF\n# END Wordfence WAF\n", array() ) );
		$this->assertFalse( $validator->is_valid( $block . $block . "# END Wordfence WAF\n", array() ) );
	}

	/**
	 * Unlisted bare comments do not open blocks.
	 *
	 * @return void
	 */
	public function test_unlisted_bare_comment_does_not_open_a_block() {
		$validator = new Validator();
		$this->assertFalse( $validator->is_valid( "# Foo\n# END Foo\n", array() ) );
	}

	/**
	 * The filter adds formats; a non-array return falls back to the defaults.
	 *
	 * @return void
	 */
	public function test_known_block_formats_filter() {
		$validator = new Validator();
		$text      = "# Acme Rules\nHeader set X 1\n# END Acme Rules\n";
		$this->assertFalse( $validator->is_valid( $text, array() ) );

		$add = static function ( $formats ) {
			$formats['Acme Rules'] = 'Acme Rules';
			return $formats;
		};
		add_filter( 'nfd_htaccess_known_block_formats', $add );
		$this->assertTrue( $validator->is_valid( $text, array() ) );
		remove_filter( 'nfd_htaccess_known_block_formats', $add );

		add_filter( 'nfd_htaccess_known_block_formats', '__return_false' );
		$this->assertTrue( $validator->is_valid( "# Wordfence WAF\n# END Wordfence WAF\n", array() ) );
		remove_filter( 'nfd_htaccess_known_block_formats', '__return_false' );
	}

	/**
	 * An empty array turns the list off, so a Wordfence END is a mismatch again.
	 *
	 * @return void
	 */
	public function test_known_block_formats_filter_empty_array_turns_list_off() {
		$validator = new Validator();
		add_filter( 'nfd_htaccess_known_block_formats', '__return_empty_array' );
		$this->assertFalse( $validator->is_valid( "# Wordfence WAF\n# END Wordfence WAF\n", array() ) );
		$this->assertTrue( $validator->is_valid( "# BEGIN WordPress\n# END WordPress\n", array() ) );
		remove_filter( 'nfd_htaccess_known_block_formats', '__return_empty_array' );
	}

	/**
	 * Odd filter entries are skipped or normalized without warnings.
	 *
	 * @return void
	 */
	public function test_known_block_formats_filter_skips_odd_entries() {
		$validator = new Validator();
		$odd       = static function () {
			return array(
				'404'      => '404',
				''         => 'Empty Opener',
				'No Label' => '',
				'Array'    => array( 'x' ),
				7          => 'Seven',
			);
		};
		add_filter( 'nfd_htaccess_known_block_formats', $odd );

		$this->assertTrue( $validator->is_valid( "# 404\n# END 404\n", array() ) );
		$this->assertTrue( $validator->is_valid( "# 7\n# END Seven\n", array() ) );
		$this->assertFalse( $validator->is_valid( "# Array\n# END Array\n", array() ) );
		$this->assertFalse( $validator->is_valid( "# No Label\n# END No Label\n", array() ) );
		$this->assertFalse( $validator->is_valid( "# Wordfence WAF\n# END Wordfence WAF\n", array() ) );

		remove_filter( 'nfd_htaccess_known_block_formats', $odd );
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
