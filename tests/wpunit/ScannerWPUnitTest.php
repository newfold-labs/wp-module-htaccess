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
	 * Updater writing the given file instead of the site's .htaccess.
	 *
	 * Without this the Updater resolves its own path from get_home_path(), so a
	 * remediate() test would write the test install's real file and its
	 * assertions would pass for the wrong reason.
	 *
	 * @param string $path File the updater should write.
	 * @return Updater
	 */
	private function create_updater( $path ) {
		// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- overrides inherit the parent docblocks
		return new class( $path ) extends Updater {
			/**
			 * File to write instead of the site's .htaccess.
			 *
			 * @var string
			 */
			private $test_path;
			public function __construct( $test_path ) {
				parent::__construct();
				$this->test_path = $test_path;
			}
			protected function get_htaccess_path() {
				return $this->test_path;
			}
		};
		// phpcs:enable Squiz.Commenting.FunctionComment.Missing
	}

	/**
	 * Scanner reading and writing the given file instead of the site's .htaccess.
	 *
	 * @param string       $path    File the scanner should act on.
	 * @param Updater|null $updater Updater to use. Defaults to a path-isolated one.
	 * @return Scanner
	 */
	private function create_scanner( $path, $updater = null ) {
		$updater = ( null === $updater ) ? $this->create_updater( $path ) : $updater;

		// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- overrides inherit the parent docblocks
		return new class( $updater, new Validator(), $path ) extends Scanner {
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
	 * Context stub exposing the single method remediate() reads.
	 *
	 * @return object
	 */
	private function create_context_stub() {
		// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- single-method stub
		return new class() {
			public function host() {
				return 'example.com';
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
			if ( is_multisite() ) {
				delete_site_option( $key );
				return;
			}

			delete_option( $key );
			return;
		}

		// Manager::save_state_full() branches the same way. Writing the
		// single-site option on a multisite run would leave load_saved_body()
		// reading an empty store and every test would silently exercise the
		// compose fallback instead.
		if ( is_multisite() ) {
			update_site_option( $key, array( 'body' => $body ) );
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
	 * A block that no longer matches the persisted body is drift.
	 *
	 * @return void
	 */
	public function test_scan_detects_a_block_that_drifted_from_saved_state() {
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
		$this->assertSame( hash( 'sha256', $body ), $report['expected_checksum'] );

		$this->clean_up( $path );
	}

	/**
	 * Nothing to compare against is not the same as drift.
	 *
	 * With no saved state and nothing registered, the expected body is empty.
	 * Reporting that as drift would hand remediate() an empty body, which is
	 * how Updater is told to delete the block. Cron and WP-CLI are the requests
	 * that reach the scanner with a short registry, so this has to hold.
	 *
	 * @return void
	 */
	public function test_an_empty_expected_body_is_not_drift() {
		$path = $this->write_block( "# BEGIN A\nHeader set X \"1\"\n# END A" );
		$this->set_saved_state( null );

		$report = $this->create_scanner( $path )->scan( null, array() );

		$this->assertSame( 'ok', $report['status'] );
		$this->assertFalse( $report['can_remediate'] );

		$this->clean_up( $path );
	}

	/**
	 * Remediation is refused when there is no body to write.
	 *
	 * It returns before reaching the Updater, so nothing touches the filesystem.
	 * An empty body is what Updater reads as an instruction to delete the block.
	 *
	 * @return void
	 */
	public function test_remediate_refuses_an_empty_expected_body() {
		$path = $this->write_block( "# BEGIN A\nHeader set X \"1\"\n# END A" );
		$this->set_saved_state( null );

		// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- override inherits the parent docblock
		$updater = new class() extends Updater {
			/**
			 * Number of write attempts seen.
			 *
			 * @var int
			 */
			public $writes = 0;
			public function apply_managed_block( $body, $host, $version, $legacy_labels = array() ) {
				++$this->writes;
				return true;
			}
		};
		// phpcs:enable Squiz.Commenting.FunctionComment.Missing

		$applied = $this->create_scanner( $path, $updater )->remediate( $this->create_context_stub(), array(), '1.0.0' );

		$this->assertFalse( $applied );
		$this->assertSame( 0, $updater->writes );

		$this->clean_up( $path );
	}

	/**
	 * A null context does not take remediation down.
	 *
	 * Cron builds the context conditionally and can pass null. The host is only
	 * used for a comment line that the body hash ignores, so it is read late and
	 * defensively rather than on the first line.
	 *
	 * @return void
	 */
	public function test_remediate_survives_a_null_context() {
		$body = "# BEGIN A\nHeader set X \"1\"\n# END A";
		$path = $this->write_block( $body );
		$this->set_saved_state( $body );

		// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- override inherits the parent docblock
		$updater = new class() extends Updater {
			/**
			 * Number of write attempts seen.
			 *
			 * @var int
			 */
			public $writes = 0;
			public function apply_managed_block( $body, $host, $version, $legacy_labels = array() ) {
				++$this->writes;
				return true;
			}
		};
		// phpcs:enable Squiz.Commenting.FunctionComment.Missing

		$applied = $this->create_scanner( $path, $updater )->remediate( null, array(), '1.0.0' );

		$this->assertTrue( $applied );
		$this->assertSame( 1, $updater->writes );

		$this->clean_up( $path );
	}

	/**
	 * A composed body is never written over a block that already exists.
	 *
	 * The registry only holds what registered on this request, and the image
	 * rules are never in it, so composing can come up short of what is on disk.
	 * Drift is still reported, it just does not authorise the write.
	 *
	 * @return void
	 */
	public function test_a_composed_body_does_not_overwrite_an_existing_block() {
		$on_disk = "# BEGIN A\nHeader set X \"1\"\n# END A\n\n# BEGIN B\nHeader set Y \"1\"\n# END B";

		$path = $this->write_block( $on_disk );
		$this->set_saved_state( null );

		// Only fragment A is registered, so composing would drop B.
		$report = $this->create_scanner( $path )->scan( null, array( $this->create_fragment_stub( 'a', "# BEGIN A\nHeader set X \"1\"\n# END A" ) ) );

		$this->assertSame( 'mismatch', $report['status'] );
		$this->assertFalse( $report['can_remediate'] );

		$this->clean_up( $path );
	}

	/**
	 * A block that is absent can still be created without saved state.
	 *
	 * Writing one where there is none cannot lose anything, so the composed
	 * body stays usable for that.
	 *
	 * @return void
	 */
	public function test_a_missing_block_can_still_be_created() {
		$path = tempnam( sys_get_temp_dir(), 'nfd-htaccess-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a local fixture.
		file_put_contents( $path, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );
		$this->set_saved_state( null );

		$report = $this->create_scanner( $path )->scan( null, array( $this->create_fragment_stub( 'a', "# BEGIN A\nHeader set X \"1\"\n# END A" ) ) );

		$this->assertSame( 'missing', $report['status'] );
		$this->assertTrue( $report['can_remediate'] );

		$this->clean_up( $path );
	}

	/**
	 * A body opening on a blank line still matches itself on disk.
	 *
	 * The on-disk hash canonicalizes a block that carries its header lines. Run
	 * over a bare body the same helper would strip the body's own first line
	 * instead, so the two sides have to be hashed differently.
	 *
	 * @return void
	 */
	public function test_a_body_starting_with_a_blank_line_is_not_drift() {
		$body = "\n# BEGIN A\nHeader set X \"1\"\n# END A";

		$path = $this->write_block( $body );
		$this->set_saved_state( $body );

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
