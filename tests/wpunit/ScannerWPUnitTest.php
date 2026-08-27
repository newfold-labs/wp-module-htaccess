<?php

namespace NewfoldLabs\WP\Module\Htaccess;

/**
 * Tests for Scanner drift detection.
 *
 * @covers \NewfoldLabs\WP\Module\Htaccess\Scanner
 */
class ScannerWPUnitTest extends \lucatume\WPBrowser\TestCase\WPTestCase {

	/**
	 * Fragment rendering a fixed body.
	 *
	 * @param string $id   Fragment ID.
	 * @param string $body Rendered body.
	 * @return Fragment
	 */
	private function create_fragment_stub( $id, $body ) {
		// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Fragment interface impl in anonymous class
		return new class( $id, $body ) implements Fragment {
			/**
			 * Fragment ID.
			 *
			 * @var string
			 */
			private $id;

			/**
			 * Rendered body.
			 *
			 * @var string
			 */
			private $body;
			public function __construct( $id, $body ) {
				$this->id   = $id;
				$this->body = $body;
			}
			public function id() {
				return $this->id;
			}
			public function priority() {
				return 50;
			}
			public function exclusive() {
				return true;
			}
			public function is_enabled( $context ) {
				return true;
			}
			public function render( $context ) {
				return $this->body;
			}
			public function patches( $context ) {
				return array();
			}
		};
		// phpcs:enable Squiz.Commenting.FunctionComment.Missing
	}

	/**
	 * Scanner reading the given file instead of the site's .htaccess.
	 *
	 * @param string $path File the scanner should read.
	 * @return Scanner
	 */
	private function create_scanner( $path ) {
		// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- overrides inherit the parent docblocks
		return new class( new Updater(), new Validator(), $path ) extends Scanner {
			/**
			 * File to read instead of the site's .htaccess.
			 *
			 * @var string
			 */
			private $test_path;
			public function __construct( Updater $updater, Validator $validator, $test_path ) {
				parent::__construct( $updater, $validator );
				$this->test_path = $test_path;
			}
			protected function get_htaccess_path() {
				return $this->test_path;
			}
			protected function read_file( $path ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local fixture, not a URL.
				return file_exists( $path ) ? (string) file_get_contents( $path ) : '';
			}
		};
		// phpcs:enable Squiz.Commenting.FunctionComment.Missing
	}

	/**
	 * Write a managed block holding the given body and return its path.
	 *
	 * @param string $body        Block body.
	 * @param string $header_hash Optional hash for the STATE header. Defaults to the body's own.
	 * @return string Path to the file written.
	 */
	private function write_block( $body, $header_hash = null ) {
		$path   = tempnam( sys_get_temp_dir(), 'nfd-htaccess-' );
		$body   = Text::normalize_lf( $body, true );
		$hash   = ( null === $header_hash ) ? hash( 'sha256', $body ) : $header_hash;
		$marker = Config::marker();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a local fixture.
		file_put_contents(
			$path,
			'# BEGIN ' . $marker . "\n"
			. "# Managed by Newfold Htaccess Manager v1.0.1 (example.com)\n"
			. '# STATE sha256: ' . $hash . " applied: 2026-01-01T00:00:00Z\n\n"
			. $body . "\n"
			. '# END ' . $marker . "\n"
		);

		return $path;
	}

	/**
	 * Put a composed body in saved state, or clear it when null.
	 *
	 * @param string|null $body Composed body, or null to clear.
	 * @return void
	 */
	private function set_saved_state( $body ) {
		$key = Options::get_option_name( 'saved_state' );

		if ( null === $body ) {
			delete_option( $key );
			return;
		}

		update_option( $key, array( 'body' => $body ) );
	}

	/**
	 * Drop the file and saved state a test created.
	 *
	 * @param string $path File to remove.
	 * @return void
	 */
	private function clean_up( $path ) {
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
		$this->set_saved_state( null );
	}

	/**
	 * A block that no longer matches what the code renders is drift.
	 *
	 * @return void
	 */
	public function test_scan_detects_a_changed_render() {
		$old = "# BEGIN A\nHeader set X \"1\"\n# END A";
		$new = "# BEGIN A\nHeader set X \"2\"\n# END A";

		$path = $this->write_block( $old );
		$this->set_saved_state( $new );

		$report = $this->create_scanner( $path )->scan( null, array( $this->create_fragment_stub( 'a', $new ) ) );

		$this->assertSame( 'mismatch', $report['status'] );
		$this->assertTrue( $report['can_remediate'] );

		$this->clean_up( $path );
	}

	/**
	 * The expected checksum comes from the expected body, not the file header.
	 *
	 * @return void
	 */
	public function test_expected_checksum_is_not_read_from_the_header() {
		$old = "# BEGIN A\nHeader set X \"1\"\n# END A";
		$new = "# BEGIN A\nHeader set X \"2\"\n# END A";

		$path = $this->write_block( $old );
		$this->set_saved_state( $new );

		$report = $this->create_scanner( $path )->scan( null, array( $this->create_fragment_stub( 'a', $new ) ) );

		$this->assertSame( hash( 'sha256', $new ), $report['expected_checksum'] );
		$this->assertSame( hash( 'sha256', $old ), $report['current_checksum'] );

		$this->clean_up( $path );
	}

	/**
	 * A request that registered only some fragments must not report drift.
	 *
	 * Cron reaches init but not admin_init, where most consumers register, so
	 * the expected body has to come from saved state and not the registry.
	 *
	 * @return void
	 */
	public function test_a_partial_registry_does_not_report_drift() {
		$a    = "# BEGIN A\nHeader set X \"1\"\n# END A";
		$b    = "# BEGIN B\nHeader set Y \"1\"\n# END B";
		$both = $a . "\n\n" . $b;

		$path = $this->write_block( $both );
		$this->set_saved_state( $both );

		// Only fragment A registered on this request.
		$report = $this->create_scanner( $path )->scan( null, array( $this->create_fragment_stub( 'a', $a ) ) );

		$this->assertSame( 'ok', $report['status'] );
		$this->assertFalse( $report['can_remediate'] );

		$this->clean_up( $path );
	}

	/**
	 * Without saved state the expected body is composed from the fragments.
	 *
	 * @return void
	 */
	public function test_scan_falls_back_to_composing_from_fragments() {
		$body = "# BEGIN A\nHeader set X \"1\"\n# END A";

		$path = $this->write_block( $body );
		$this->set_saved_state( null );

		$report = $this->create_scanner( $path )->scan( null, array( $this->create_fragment_stub( 'a', $body ) ) );

		$this->assertSame( 'ok', $report['status'] );

		$this->clean_up( $path );
	}

	/**
	 * A stale header over a correct body is reported without failing the scan.
	 *
	 * @return void
	 */
	public function test_a_stale_header_does_not_fail_the_scan() {
		$body = "# BEGIN A\nHeader set X \"1\"\n# END A";

		$path = $this->write_block( $body, str_repeat( 'a', 64 ) );
		$this->set_saved_state( $body );

		$report = $this->create_scanner( $path )->scan( null, array( $this->create_fragment_stub( 'a', $body ) ) );

		$this->assertSame( 'ok', $report['status'] );
		$this->assertNotEmpty( $report['issues'] );

		$this->clean_up( $path );
	}
}
