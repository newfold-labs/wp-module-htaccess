<?php

namespace NewfoldLabs\WP\Module\Htaccess;

/**
 * Tests for Config class.
 *
 * @covers \NewfoldLabs\WP\Module\Htaccess\Config
 */
class ConfigWPUnitTest extends \lucatume\WPBrowser\TestCase\WPTestCase {

	/**
	 * Marker returns default when no filter override.
	 *
	 * @return void
	 */
	public function test_marker_returns_default() {
		$this->assertSame( 'NFD Htaccess', Config::marker() );
	}

	/**
	 * DEFAULT_MARKER constant matches marker() default.
	 *
	 * @return void
	 */
	public function test_default_marker_constant() {
		$this->assertSame( Config::DEFAULT_MARKER, Config::marker() );
	}
}
