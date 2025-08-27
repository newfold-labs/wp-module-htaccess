<?php
/**
 * Writer for canonical .htaccess payloads.
 *
 * Handles atomic writes with rolling backups and a simple lock to
 * prevent concurrent modifications. Prefers WP_Filesystem, falls
 * back to direct PHP I/O when unavailable.
 *
 * @package NewfoldLabs\WP\Module\Htaccess
 */

namespace NewfoldLabs\WP\Module\Htaccess;

use WP_Filesystem_Base;

/**
 * Class Writer
 *
 * @since 1.0.0
 */
class Writer {

	/**
	 * Maximum number of backups to retain.
	 *
	 * @var int
	 */
	protected $max_backups = 10;

	/**
	 * Filesystem instance (may be null until initialized).
	 *
	 * @var WP_Filesystem_Base|null
	 */
	protected $fs = null;

	/**
	 * Set maximum number of backups to retain.
	 *
	 * @since 1.0.0
	 *
	 * @param int $count Backup count (minimum 1).
	 * @return void
	 */
	public function set_max_backups( $count ) {
		$count             = (int) $count;
		$this->max_backups = ( $count >= 1 ) ? $count : 1;
	}

	/**
	 * Write .htaccess content atomically with backup and verification.
	 *
	 * @since 1.0.0
	 *
	 * @param string $contents Canonical htaccess text to write.
	 * @return bool True on success, false on failure.
	 */
	public function write( $contents ) {
		$contents = (string) $contents;

		$path = $this->get_htaccess_path();
		if ( '' === $path ) {
			return false;
		}

		$dir = dirname( $path );

		// Initialize filesystem (best-effort).
		$this->init_filesystem();

		// Acquire a simple lock to avoid concurrent writes.
		$lock    = $dir . DIRECTORY_SEPARATOR . '.nfd_htaccess.lock';
		$lock_fp = $this->acquire_lock( $lock );
		if ( ! $lock_fp ) {
			// If we cannot lock, avoid risky writes.
			return false;
		}

		// Read current content for backup.
		$current = $this->read_file( $path );

		// Create a timestamped backup (even if current is empty, we write a backup of what exists).
		$this->create_backup( $dir, $path, $current );

		// Write to a temporary file first.
		$tmp = $this->temp_path_for( $path );

		if ( ! $this->write_file( $tmp, $contents ) ) {
			$this->release_lock( $lock_fp, $lock );
			return false;
		}

		// Verify tmp content matches desired content (checksum check).
		$verify = $this->read_file( $tmp );
		if ( hash( 'sha256', (string) $verify ) !== hash( 'sha256', $contents ) ) {
			$this->delete_file( $tmp );
			$this->release_lock( $lock_fp, $lock );
			return false;
		}

		// Atomic swap: move tmp over final.
		$swapped = $this->move_file( $tmp, $path );
		if ( ! $swapped ) {
			// Cleanup tmp if possible.
			$this->delete_file( $tmp );
			$this->release_lock( $lock_fp, $lock );
			return false;
		}

		// Final verification: read back and compare checksum.
		$final = $this->read_file( $path );
		$ok    = ( hash( 'sha256', (string) $final ) === hash( 'sha256', $contents ) );

		// Prune old backups.
		$this->prune_backups( $dir );

		$this->release_lock( $lock_fp, $lock );

		return $ok;
	}

	/**
	 * Determine the .htaccess file path for this site.
	 *
	 * @since 1.0.0
	 *
	 * @return string Absolute path or empty string on failure.
	 */
	protected function get_htaccess_path() {
		$path = '';

		// Prefer WordPress helpers where available.
		if ( function_exists( 'get_home_path' ) ) {
			$home = get_home_path();
			if ( is_string( $home ) && '' !== $home ) {
				$path = rtrim( $home, "/\\ \t\n\r\0\x0B" ) . DIRECTORY_SEPARATOR . '.htaccess';
			}
		}

		// Fallback to ABSPATH if needed.
		if ( '' === $path && defined( 'ABSPATH' ) ) {
			$path = rtrim( ABSPATH, "/\\ \t\n\r\0\x0B" ) . DIRECTORY_SEPARATOR . '.htaccess';
		}

		return is_string( $path ) ? $path : '';
	}

	/**
	 * Initialize WP_Filesystem if possible.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function init_filesystem() {
		if ( $this->fs instanceof WP_Filesystem_Base ) {
			return;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			if ( file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
		}

		// Attempt direct filesystem method; credentials flow is not desired in module context.
		if ( function_exists( 'WP_Filesystem' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
			WP_Filesystem();
			global $wp_filesystem;
			if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
				$this->fs = $wp_filesystem;
			}
		}
	}

	/**
	 * Acquire an exclusive lock via a lock file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $lock_path Lock file path.
	 * @return resource|false File handle on success, false on failure.
	 */
	protected function acquire_lock( $lock_path ) {
		// Use native fopen/flock even if WP_Filesystem is present.
		$fp = @fopen( $lock_path, 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $fp ) {
			return false;
		}

		if ( ! @flock( $fp, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@fclose( $fp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		return $fp;
	}

	/**
	 * Release the lock and remove the lock file.
	 *
	 * @since 1.0.0
	 *
	 * @param resource $fp        File pointer.
	 * @param string   $lock_path Lock file path.
	 * @return void
	 */
	protected function release_lock( $fp, $lock_path ) {
		if ( is_resource( $fp ) ) {
			@flock( $fp, LOCK_UN ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@fclose( $fp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		// Best-effort cleanup; ignore failure.
		$this->delete_file( $lock_path );
	}

	/**
	 * Create a timestamped backup of the current .htaccess (if any).
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir     Directory for .htaccess.
	 * @param string $path    .htaccess path.
	 * @param string $current Current contents (may be empty).
	 * @return void
	 */
	protected function create_backup( $dir, $path, $current ) {
		// If file does not exist and content empty, nothing to back up.
		if ( '' === (string) $current && ! $this->exists( $path ) ) {
			return;
		}

		$name = $this->backup_name();
		$bak  = $dir . DIRECTORY_SEPARATOR . $name;

		$this->write_file( $bak, (string) $current );
	}

	/**
	 * Generate backup file name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function backup_name() {
		$ts = gmdate( 'Ymd-His' );
		return '.htaccess.' . $ts . '.bak';
	}

	/**
	 * Prune old backups beyond retention count.
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir Directory containing backups.
	 * @return void
	 */
	protected function prune_backups( $dir ) {
		$files = $this->list_dir( $dir );
		if ( empty( $files ) ) {
			return;
		}

		$backups = array();
		foreach ( $files as $f ) {
			if ( preg_match( '/^\.htaccess\.\d{8}-\d{6}\.bak$/', $f ) ) {
				$backups[] = $f;
			}
		}

		if ( count( $backups ) <= $this->max_backups ) {
			return;
		}

		sort( $backups, SORT_STRING ); // Oldest first due to timestamp in name.

		$to_delete = array_slice( $backups, 0, count( $backups ) - $this->max_backups );
		foreach ( $to_delete as $f ) {
			$this->delete_file( $dir . DIRECTORY_SEPARATOR . $f );
		}
	}

	/**
	 * Build a temp path next to the final file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $final_path Final file path.
	 * @return string Temp file path.
	 */
	protected function temp_path_for( $final_path ) {
		$dir  = dirname( $final_path );
		$base = basename( $final_path );
		$rand = wp_generate_password( 8, false, false );
		return $dir . DIRECTORY_SEPARATOR . '.' . $base . '.tmp.' . $rand;
	}

	/**
	 * Read a file using WP_Filesystem or native fallback.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Absolute file path.
	 * @return string File contents or empty string.
	 */
	protected function read_file( $path ) {
		if ( $this->fs instanceof WP_Filesystem_Base ) {
			if ( $this->fs->exists( $path ) && $this->fs->is_file( $path ) ) {
				$buf = $this->fs->get_contents( $path );
				return is_string( $buf ) ? $buf : '';
			}
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$buf = @file_get_contents( $path );
		return is_string( $buf ) ? $buf : '';
	}

	/**
	 * Write a file using WP_Filesystem or native fallback.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Absolute file path.
	 * @param string $data Data to write.
	 * @return bool
	 */
	protected function write_file( $path, $data ) {
		if ( $this->fs instanceof WP_Filesystem_Base ) {
			$dir = dirname( $path );
			if ( ! $this->fs->is_dir( $dir ) ) {
				$this->fs->mkdir( $dir );
			}
			return (bool) $this->fs->put_contents( $path, (string) $data, FS_CHMOD_FILE );
		}

		// Native fallback with exclusive write.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
		$fp = @fopen( $path, 'wb' );
		if ( false === $fp ) {
			return false;
		}

		$ok = ( false !== @fwrite( $fp, (string) $data ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@fclose( $fp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		// Best-effort perms similar to FS_CHMOD_FILE.
		if ( $ok ) {
			@chmod( $path, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		return $ok;
	}

	/**
	 * Move/rename a file over another path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from Source path.
	 * @param string $to   Destination path.
	 * @return bool
	 */
	protected function move_file( $from, $to ) {
		if ( $this->fs instanceof WP_Filesystem_Base ) {
			// Use ->move() where available; second param true = overwrite.
			if ( method_exists( $this->fs, 'move' ) ) {
				return (bool) $this->fs->move( $from, $to, true );
			}
			// Fallback: delete destination then copy.
			if ( $this->fs->exists( $to ) ) {
				$this->fs->delete( $to );
			}
			$copied = $this->fs->copy( $from, $to, true, FS_CHMOD_FILE );
			if ( $copied ) {
				$this->fs->delete( $from );
			}
			return (bool) $copied;
		}

		// Native rename attempt (usually atomic on same filesystem).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( @rename( $from, $to ) ) {
			return true;
		}

		// Fallback: copy then delete.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		$copied = @copy( $from, $to );
		if ( $copied ) {
			$this->delete_file( $from );
			return true;
		}

		return false;
	}

	/**
	 * Delete a file via WP_Filesystem or native fallback.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Absolute path.
	 * @return void
	 */
	protected function delete_file( $path ) {
		if ( $this->fs instanceof WP_Filesystem_Base ) {
			if ( $this->fs->exists( $path ) ) {
				$this->fs->delete( $path );
			}
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $path );
	}

	/**
	 * List files in a directory (names only).
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir Directory path.
	 * @return string[] Filenames (no paths).
	 */
	protected function list_dir( $dir ) {
		$result = array();

		if ( $this->fs instanceof WP_Filesystem_Base ) {
			if ( ! $this->fs->is_dir( $dir ) ) {
				return $result;
			}
			$list = $this->fs->dirlist( $dir, false, true );
			if ( is_array( $list ) ) {
				foreach ( $list as $name => $info ) {
					$result[] = $name;
				}
			}
			return $result;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.directory_functions_opendir
		$dh = @opendir( $dir );
		if ( false === $dh ) {
			return $result;
		}

		// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( false !== ( $name = @readdir( $dh ) ) ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$result[] = $name;
		}
		@closedir( $dh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.directory_functions_closedir

		return $result;
	}

	/**
	 * Check existence of a file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Path to check.
	 * @return bool
	 */
	protected function exists( $path ) {
		if ( $this->fs instanceof WP_Filesystem_Base ) {
			return (bool) $this->fs->exists( $path );
		}
		return file_exists( $path );
	}
}
