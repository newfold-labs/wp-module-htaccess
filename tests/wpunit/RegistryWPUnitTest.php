<?php

namespace NewfoldLabs\WP\Module\Htaccess;

/**
 * Tests for Registry class.
 *
 * @covers \NewfoldLabs\WP\Module\Htaccess\Registry
 */
class RegistryWPUnitTest extends \lucatume\WPBrowser\TestCase\WPTestCase {

	/**
	 * Create a minimal fragment stub for testing.
	 *
	 * @param string $id       Fragment ID.
	 * @param int    $priority Priority.
	 * @param bool   $enabled  Whether is_enabled returns true.
	 * @return Fragment
	 */
	private function create_fragment_stub( $id = 'test-fragment', $priority = 100, $enabled = true ) {
		// phpcs:disable Squiz.Commenting.VariableComment.Missing,Squiz.Commenting.FunctionComment.Missing -- Fragment impl in anonymous class
		return new class( $id, $priority, $enabled ) implements Fragment {
			private $id;
			private $priority;
			private $enabled;
			public function __construct( $id, $priority, $enabled ) {
				$this->id       = $id;
				$this->priority = $priority;
				$this->enabled  = $enabled;
			}
			public function id() {
				return $this->id;
			}
			public function priority() {
				return $this->priority;
			}
			public function exclusive() {
				return false;
			}
			public function is_enabled( $context ) {
				return $this->enabled;
			}
			public function render( $context ) {
				return '# BEGIN ' . $this->id . "\n# END " . $this->id;
			}
			public function patches( $context ) {
				return array();
			}
		};
		// phpcs:enable Squiz.Commenting.VariableComment.Missing,Squiz.Commenting.FunctionComment.Missing
	}

	/**
	 * Register adds fragment and returns true when changed.
	 *
	 * @return void
	 */
	public function test_register_adds_fragment() {
		$registry = new Registry();
		$fragment = $this->create_fragment_stub( 'my-id', 200 );
		$this->assertTrue( $registry->register( $fragment ) );
		$this->assertTrue( $registry->has( 'my-id' ) );
		$this->assertSame( 1, $registry->count() );
		$this->assertSame( $fragment, $registry->get( 'my-id' ) );
	}

	/**
	 * Register with empty id returns false.
	 *
	 * @return void
	 */
	public function test_register_with_empty_id_returns_false() {
		$registry = new Registry();
		$fragment = $this->create_fragment_stub( '', 100 );
		$this->assertFalse( $registry->register( $fragment ) );
		$this->assertSame( 0, $registry->count() );
	}

	/**
	 * Unregister removes fragment by id.
	 *
	 * @return void
	 */
	public function test_unregister_removes_fragment() {
		$registry = new Registry();
		$fragment = $this->create_fragment_stub( 'remove-me' );
		$registry->register( $fragment );
		$this->assertTrue( $registry->unregister( 'remove-me' ) );
		$this->assertFalse( $registry->has( 'remove-me' ) );
		$this->assertNull( $registry->get( 'remove-me' ) );
	}

	/**
	 * Unregister returns false when id not present.
	 *
	 * @return void
	 */
	public function test_unregister_returns_false_when_not_present() {
		$registry = new Registry();
		$this->assertFalse( $registry->unregister( 'nonexistent' ) );
	}

	/**
	 * All and ids return registered fragments.
	 *
	 * @return void
	 */
	public function test_all_and_ids() {
		$registry = new Registry();
		$registry->register( $this->create_fragment_stub( 'a' ) );
		$registry->register( $this->create_fragment_stub( 'b' ) );
		$this->assertCount( 2, $registry->all() );
		$this->assertSame( array( 'a', 'b' ), $registry->ids() );
	}

	/**
	 * Enabled_fragments returns only enabled fragments sorted by priority.
	 *
	 * @return void
	 */
	public function test_enabled_fragments_sorted_by_priority() {
		$registry = new Registry();
		$high     = $this->create_fragment_stub( 'high', 10, true );
		$low      = $this->create_fragment_stub( 'low', 100, true );
		$registry->register( $low );
		$registry->register( $high );
		$enabled = $registry->enabled_fragments( null );
		$this->assertCount( 2, $enabled );
		$this->assertSame( 'high', $enabled[0]->id() );
		$this->assertSame( 'low', $enabled[1]->id() );
	}

	/**
	 * Clear removes all fragments.
	 *
	 * @return void
	 */
	public function test_clear() {
		$registry = new Registry();
		$registry->register( $this->create_fragment_stub( 'x' ) );
		$registry->clear();
		$this->assertSame( 0, $registry->count() );
		$this->assertFalse( $registry->has( 'x' ) );
	}
}
