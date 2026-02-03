<?php

namespace NewfoldLabs\WP\Module\Htaccess;

/**
 * Tests for Api static helper class.
 *
 * @covers \NewfoldLabs\WP\Module\Htaccess\Api
 */
class ApiWPUnitTest extends \lucatume\WPBrowser\TestCase\WPTestCase {

	/**
	 * Returns a Registry instance from Api::registry().
	 *
	 * @return void
	 */
	public function test_registry_returns_registry_instance() {
		$registry = Api::registry();
		$this->assertInstanceOf( Registry::class, $registry );
	}

	/**
	 * Returns array from registry via Api::enabled_fragments().
	 *
	 * @return void
	 */
	public function test_enabled_fragments_delegates_to_registry() {
		$fragments = Api::enabled_fragments( null );
		$this->assertIsArray( $fragments );
	}
}
