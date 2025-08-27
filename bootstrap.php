<?php
/**
 * Bootstrap for wp-module-htaccess.
 *
 * @package NewfoldLabs\WP\Module\Htaccess
 */

use NewfoldLabs\WP\Module\Htaccess\Api;
use NewfoldLabs\WP\Module\Htaccess\Fragments\DemoHeader;
use NewfoldLabs\WP\Module\Htaccess\Fragments\ForceHttps;
use NewfoldLabs\WP\ModuleLoader\Container;
use NewfoldLabs\WP\Module\Htaccess\Manager;
use function NewfoldLabs\WP\ModuleLoader\register;

if ( function_exists( 'add_action' ) ) {
	add_action(
		'plugins_loaded',
		function () {
			register(
				array(
					'name'     => 'wp-module-htaccess',
					'label'    => __( 'Htaccess', 'wp-module-htaccess' ),
					'callback' => function ( Container $container ) {
						if ( ! defined( 'NFD_MODULE_HTACCESS_DIR' ) ) {
							define( 'NFD_MODULE_HTACCESS_DIR', __DIR__ );
						}

						$manager = new Manager( $container );
						if ( method_exists( $manager, 'boot' ) ) {
							$manager->boot();
						}

						// DEBUG: prove bootstrap ran.
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( '[htaccess-manager] bootstrap callback fired' ); // phpcs:ignore
						}

						// Register fragments WITHOUT auto-queue to avoid loops.
						Api::register( new DemoHeader(), false );

						// Force a single apply WHEN you visit wp-admin (easy, visible).
						add_action(
							'admin_init',
							function () {
								if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                    error_log( '[htaccess-manager] admin_init – forcing apply_now' ); // phpcs:ignore
								}
								do_action( 'nfd_htaccess_apply_now' );
							}
						);
					},
					'isActive' => true,
					'isHidden' => true,
				)
			);
		}
	);
}
