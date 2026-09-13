<?php
/**
 * Plugin Name: Fake Plugin
 * Plugin URI: https://example.com/fake-plugin
 * Description: Fixture plugin exercising epiphyt/wp-plugin-updater end to end.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Epiphyt
 * License: GPL-2.0-only
 * Text Domain: fake-plugin
 */
declare(strict_types = 1);

namespace epiphyt\Fake_Plugin;

use epiphyt\Plugin_Updater\Config;
use epiphyt\Plugin_Updater\Registry;
use epiphyt\Plugin_Updater\Strings;

\defined( 'ABSPATH' ) || exit;

\define( 'FAKE_PLUGIN_FILE', __FILE__ );
\define( 'FAKE_PLUGIN_BASE', \plugin_basename( __FILE__ ) );

// the end-to-end harness substitutes these at build time
\define( 'FAKE_PLUGIN_UPDATE_URL', '__UPDATE_URL__' );
\define( 'FAKE_PLUGIN_LICENSE_URL', '__LICENSE_URL__' );

/**
 * Autoload the updater package.
 *
 * The real plugins use Composer's classmap for this. The harness stages the
 * package by copying inc/ rather than by running Composer, so the file naming
 * convention is resolved by hand here: Foo lives in class-foo.php, Foo_Interface
 * in interface-foo.php.
 *
 * An unresolvable class in this namespace is always a harness bug, so it fails
 * loudly instead of leaving PHP to report a missing type somewhere else.
 */
\spl_autoload_register( static function ( string $class_name ): void {
	$prefix = 'epiphyt\\Plugin_Updater\\';

	if ( ! \str_starts_with( $class_name, $prefix ) ) {
		return;
	}

	$directory = __DIR__ . '/vendor/epiphyt/wp-plugin-updater/inc/';
	$name = \str_replace( '_', '-', \strtolower( \substr( $class_name, \strlen( $prefix ) ) ) );
	$candidates = [ $directory . 'class-' . $name . '.php' ];

	foreach ( [ 'interface', 'trait' ] as $type ) {
		if ( \str_ends_with( $name, '-' . $type ) ) {
			$candidates[] = $directory . $type . '-' . \substr( $name, 0, -\strlen( $type ) - 1 ) . '.php';
		}
	}

	foreach ( $candidates as $file ) {
		if ( \file_exists( $file ) ) {
			require_once $file;

			return;
		}
	}

	throw new \RuntimeException( \sprintf(
		'The end-to-end fixture cannot autoload %1$s. Tried: %2$s.',
		$class_name,
		\implode( ', ', $candidates )
	) );
} );

/**
 * Bootstrap the updater on ‘init’, where translation data is available.
 */
\add_action( 'init', static function (): void {
	$config = new Config(
		plugin_basename: \FAKE_PLUGIN_BASE,
		plugin_key: 'fake_plugin',
		product_id: 'Fake Plugin',
		update_slug: 'fake-plugin',
		license_option_name: 'fake_plugin_license_options',
		settings_url: \admin_url( 'options-general.php?page=fake-plugin' ),
		settings_page_slug: 'fake-plugin',
		strings: new Strings( [
			// deliberately distinctive, so the e2e run can prove injection works
			Strings::UNKNOWN_ERROR => \__( 'INJECTED_UNKNOWN_ERROR', 'fake-plugin' ),
			Strings::UPGRADE_NOTICE => \__( 'INJECTED_UPGRADE_NOTICE', 'fake-plugin' ),
			Strings::MISSING_CREDENTIALS => \__( 'INJECTED_MISSING_CREDENTIALS', 'fake-plugin' ),
		] ),
		update_url: \FAKE_PLUGIN_UPDATE_URL,
		license_servers: [ \FAKE_PLUGIN_LICENSE_URL ],
		manages_cron: false
	);

	Registry::register( $config )->init();
}, 20 );
