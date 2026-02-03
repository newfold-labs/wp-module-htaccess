<?php

namespace NewfoldLabs\WP\Module\Htaccess;

/**
 * Tests for Options class.
 *
 * @covers \NewfoldLabs\WP\Module\Htaccess\Options
 */
class OptionsWPUnitTest extends \lucatume\WPBrowser\TestCase\WPTestCase {

	/**
	 * get_option_name returns prefixed option name for known key.
	 *
	 * @return void
	 */
	public function test_get_option_name_returns_prefixed_for_known_key() {
		$this->assertSame( 'nfd_module_htaccess_saved_state', Options::get_option_name( 'saved_state' ) );
		$this->assertSame( 'nfd_module_htaccess_needs_update', Options::get_option_name( 'needs_update' ) );
		$this->assertSame( 'nfd_module_htaccess_write_lock', Options::get_option_name( 'write_lock' ) );
		$this->assertSame( 'nfd_module_htaccess_early_fragments', Options::get_option_name( 'early_fragments' ) );
	}

	/**
	 * get_option_name with attach_prefix false returns key without prefix.
	 *
	 * @return void
	 */
	public function test_get_option_name_without_prefix() {
		$this->assertSame( 'saved_state', Options::get_option_name( 'saved_state', false ) );
	}

	/**
	 * get_option_name returns false for unknown key.
	 *
	 * @return void
	 */
	public function test_get_option_name_returns_false_for_unknown_key() {
		$this->assertFalse( Options::get_option_name( 'unknown_key' ) );
	}

	/**
	 * get_all_options returns list of option keys.
	 *
	 * @return void
	 */
	public function test_get_all_options_returns_option_keys() {
		$all = Options::get_all_options();
		$this->assertIsArray( $all );
		$this->assertArrayHasKey( 'saved_state', $all );
		$this->assertArrayHasKey( 'needs_update', $all );
		$this->assertArrayHasKey( 'early_fragments', $all );
	}
}
