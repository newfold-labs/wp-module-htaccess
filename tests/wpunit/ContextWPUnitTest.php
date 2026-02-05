<?php

namespace NewfoldLabs\WP\Module\Htaccess;

/**
 * Tests for Context class.
 *
 * @covers \NewfoldLabs\WP\Module\Htaccess\Context
 */
class ContextWPUnitTest extends \lucatume\WPBrowser\TestCase\WPTestCase {

	/**
	 * From_wp builds context with WordPress environment.
	 *
	 * @return void
	 */
	public function test_from_wp_returns_context() {
		$ctx = Context::from_wp( array() );
		$this->assertInstanceOf( Context::class, $ctx );
		$this->assertIsString( $ctx->home_url() );
		$this->assertIsString( $ctx->site_url() );
		$this->assertIsString( $ctx->host() );
		$this->assertIsBool( $ctx->is_multisite() );
		$this->assertIsBool( $ctx->is_cli() );
		$this->assertIsBool( $ctx->is_apache_like() );
		$this->assertIsArray( $ctx->active_plugins() );
	}

	/**
	 * Setting returns embedded value when key exists.
	 *
	 * @return void
	 */
	public function test_setting_returns_value_from_settings() {
		$ctx = Context::from_wp( array( 'foo' => 'bar' ) );
		$this->assertSame( 'bar', $ctx->setting( 'foo' ) );
		$this->assertSame( 'default', $ctx->setting( 'missing', 'default' ) );
	}

	/**
	 * Htaccess_path returns path when home_path is set.
	 *
	 * @return void
	 */
	public function test_htaccess_path() {
		$ctx  = Context::from_wp( array() );
		$path  = $ctx->htaccess_path();
		$this->assertIsString( $path );
		if ( '' !== $ctx->home_path() ) {
			$this->assertStringEndsWith( '.htaccess', $path );
		}
	}

	/**
	 * To_array returns associative array of context data.
	 *
	 * @return void
	 */
	public function test_to_array() {
		$ctx = Context::from_wp( array( 'k' => 'v' ) );
		$arr = $ctx->to_array();
		$this->assertIsArray( $arr );
		$this->assertArrayHasKey( 'home_url', $arr );
		$this->assertArrayHasKey( 'settings', $arr );
		$this->assertSame( 'v', $arr['settings']['k'] );
	}
}
