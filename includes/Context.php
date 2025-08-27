<?php
/**
 * Context for Htaccess composition.
 *
 * Provides environment details that fragments and services can
 * consult when deciding whether to render and how to render.
 *
 * @package NewfoldLabs\WP\Module\Htaccess
 */

namespace NewfoldLabs\WP\Module\Htaccess;

/**
 * Class Context
 *
 * Immutable snapshot of relevant site/server state.
 *
 * @since 1.0.0
 */
class Context {

	/**
	 * Home URL (string, no trailing slash).
	 *
	 * @var string
	 */
	protected $home_url = '';

	/**
	 * Site URL (string, no trailing slash).
	 *
	 * @var string
	 */
	protected $site_url = '';

	/**
	 * Host name (example.com).
	 *
	 * @var string
	 */
	protected $host = '';

	/**
	 * Absolute path to webroot that holds .htaccess.
	 *
	 * @var string
	 */
	protected $home_path = '';

	/**
	 * Whether this is a multisite network.
	 *
	 * @var bool
	 */
	protected $is_multisite = false;

	/**
	 * Whether this request is CLI.
	 *
	 * @var bool
	 */
	protected $is_cli = false;

	/**
	 * Whether this environment looks like Apache-compatible (mod_rewrite available).
	 *
	 * @var bool
	 */
	protected $is_apache_like = true;

	/**
	 * Active plugin basenames for quick checks.
	 *
	 * @var string[]
	 */
	protected $active_plugins = array();

	/**
	 * Arbitrary module settings map (optional).
	 *
	 * @var array
	 */
	protected $settings = array();

	/**
	 * Build a Context snapshot from the current WordPress environment.
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings Optional associative array of module settings to embed.
	 * @return static
	 */
	public static function from_wp( $settings = array() ) {
		$ctx = new static();

		// Home/Site URLs.
		if ( function_exists( 'home_url' ) ) {
			$ctx->home_url = untrailingslashit( home_url() );
		}
		if ( function_exists( 'site_url' ) ) {
			$ctx->site_url = untrailingslashit( site_url() );
		}

		// Host.
		if ( '' !== $ctx->home_url ) {
			$hp        = wp_parse_url( $ctx->home_url, PHP_URL_HOST );
			$ctx->host = is_string( $hp ) ? $hp : '';
		}

		// Home path for .htaccess.
		if ( function_exists( 'get_home_path' ) ) {
			$hp = get_home_path();
			if ( is_string( $hp ) && '' !== $hp ) {
				$ctx->home_path = rtrim( $hp, "/\\ \t\n\r\0\x0B" );
			}
		}

		// Multisite flag.
		$ctx->is_multisite = function_exists( 'is_multisite' ) ? (bool) is_multisite() : false;

		// CLI flag.
		$ctx->is_cli = ( defined( 'WP_CLI' ) && WP_CLI );

		// Server type: best-effort detection; treat unknown as apache-like true,
		// fragments should still guard with <IfModule mod_rewrite.c>.
		$server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) $_SERVER['SERVER_SOFTWARE'] ) : '';
		if ( false !== strpos( $server_software, 'nginx' ) ) {
			$ctx->is_apache_like = false; // NGINX: no .htaccess
		} else {
			// Apache and LiteSpeed are both .htaccess-compatible.
			$ctx->is_apache_like = true;
		}

		// Active plugins (network-aware).
		$ctx->active_plugins = $ctx->detect_active_plugins();

		// Settings.
		if ( is_array( $settings ) ) {
			$ctx->settings = $settings;
		}

		return $ctx;
	}

	/**
	 * Detect active plugins including network-activated ones.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Array of plugin basenames.
	 */
	protected function detect_active_plugins() {
		$list = array();

		$active = array();
		if ( function_exists( 'get_option' ) ) {
			$active = get_option( 'active_plugins', array() );
			if ( ! is_array( $active ) ) {
				$active = array();
			}
		}

		$network_active = array();
		if ( $this->is_multisite && function_exists( 'get_site_option' ) ) {
			$network_active = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network_active ) ) {
				$network_active = array_keys( $network_active );
			} else {
				$network_active = array();
			}
		}

		$merged = array_unique( array_merge( $active, $network_active ) );
		foreach ( $merged as $basename ) {
			if ( is_string( $basename ) && '' !== $basename ) {
				$list[] = $basename;
			}
		}

		return $list;
	}

	/**
	 * Whether a given plugin is active (basename match).
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin_basename Plugin basename, e.g. 'endurance-page-cache/endurance-page-cache.php'.
	 * @return bool
	 */
	public function is_plugin_active( $plugin_basename ) {
		return in_array( (string) $plugin_basename, $this->active_plugins, true );
	}

	/**
	 * Get a setting value by key with optional default.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default if not found.
	 * @return mixed
	 */
	public function setting( $key, $default = null ) {
		if ( isset( $this->settings[ $key ] ) ) {
			return $this->settings[ $key ];
		}
		return $default;
	}

	/**
	 * Accessors.
	 */

	/**
	 * @since 1.0.0
	 * @return string
	 */
	public function home_url() {
		return $this->home_url;
	}

	/**
	 * @since 1.0.0
	 * @return string
	 */
	public function site_url() {
		return $this->site_url;
	}

	/**
	 * @since 1.0.0
	 * @return string
	 */
	public function host() {
		return $this->host;
	}

	/**
	 * @since 1.0.0
	 * @return string
	 */
	public function home_path() {
		return $this->home_path;
	}

	/**
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_multisite() {
		return $this->is_multisite;
	}

	/**
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_cli() {
		return $this->is_cli;
	}

	/**
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_apache_like() {
		return $this->is_apache_like;
	}

	/**
	 * @since 1.0.0
	 * @return string[]
	 */
	public function active_plugins() {
		return $this->active_plugins;
	}
}
