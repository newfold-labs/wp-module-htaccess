<?php

namespace NewfoldLabs\WP\Module\Htaccess;

/**
 * Tests for Composer class.
 *
 * @covers \NewfoldLabs\WP\Module\Htaccess\Composer
 */
class ComposerWPUnitTest extends \lucatume\WPBrowser\TestCase\WPTestCase {

	/**
	 * Minimal fragment stub for composition tests.
	 *
	 * @return Fragment
	 */
	private function create_fragment_stub() {
		// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Fragment interface impl in anonymous class
		return new class() implements Fragment {
			public function id() {
				return 'test-stub';
			}
			public function priority() {
				return 100;
			}
			public function exclusive() {
				return false;
			}
			public function is_enabled( $context ) {
				return true;
			}
			public function render( $context ) {
				return "# BEGIN test-stub\nRewriteRule ^x 1 [L]\n# END test-stub";
			}
			public function patches( $context ) {
				return array();
			}
		};
		// phpcs:enable Squiz.Commenting.FunctionComment.Missing
	}

	/**
	 * Compose_body_only returns concatenated body from fragments.
	 *
	 * @return void
	 */
	public function test_compose_body_only_returns_body() {
		$fragments = array( $this->create_fragment_stub() );
		$body      = Composer::compose_body_only( $fragments, null );
		$this->assertIsString( $body );
		$this->assertStringContainsString( '# BEGIN test-stub', $body );
		$this->assertStringContainsString( 'RewriteRule', $body );
		$this->assertStringNotContainsString( 'Managed by', $body );
	}

	/**
	 * Compose_body_only with empty array returns empty string.
	 *
	 * @return void
	 */
	public function test_compose_body_only_empty_fragments() {
		$this->assertSame( '', Composer::compose_body_only( array(), null ) );
	}

	/**
	 * Composer instance compose includes header and body.
	 *
	 * @return void
	 */
	public function test_compose_includes_header() {
		$composer = new Composer();
		$composer->set_version( '1.0.0' );
		$composer->set_host( 'example.com' );
		$fragments = array( $this->create_fragment_stub() );
		$output    = $composer->compose( $fragments, null );
		$this->assertStringContainsString( 'Managed by Newfold', $output );
		$this->assertStringContainsString( 'example.com', $output );
		$this->assertStringContainsString( 'STATE sha256:', $output );
		$this->assertStringContainsString( '# BEGIN test-stub', $output );
	}
}
