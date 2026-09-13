<?php
declare(strict_types = 1);

namespace epiphyt\Plugin_Updater;

/**
 * Registry of plugin updater instances.
 * 
 * This is the only global state in this package. Instances are keyed by plugin
 * basename, which is unique per site, so any number of plugins can use this
 * package simultaneously without sharing state.
 * 
 * @author	Epiphyt
 * @license	GPL2
 * @package	epiphyt\Plugin_Updater
 */
final class Registry {
	/**
	 * @var		array<string, \epiphyt\Plugin_Updater\Plugin_Updater> Updater instances by plugin basename
	 */
	private static array $instances = [];
	
	/**
	 * Get all registered updaters.
	 * 
	 * @return	array<string, \epiphyt\Plugin_Updater\Plugin_Updater> Updater instances by plugin basename
	 */
	public static function get_all(): array {
		return self::$instances;
	}
	
	/**
	 * Get a registered updater.
	 * 
	 * @param	string	$plugin_basename Plugin basename
	 * @return	\epiphyt\Plugin_Updater\Plugin_Updater|null Updater instance, or null if not registered
	 */
	public static function get( string $plugin_basename ): ?Plugin_Updater {
		return self::$instances[ $plugin_basename ] ?? null;
	}
	
	/**
	 * Check whether an updater is registered.
	 * 
	 * @param	string	$plugin_basename Plugin basename
	 * @return	bool Whether an updater is registered
	 */
	public static function has( string $plugin_basename ): bool {
		return isset( self::$instances[ $plugin_basename ] );
	}
	
	/**
	 * Register an updater for a configuration.
	 * 
	 * Registering the same plugin twice returns the existing instance rather
	 * than replacing it, so a double bootstrap cannot produce two updaters
	 * fighting over the same options.
	 * 
	 * @param	\epiphyt\Plugin_Updater\Config	$config Plugin configuration
	 * @return	\epiphyt\Plugin_Updater\Plugin_Updater Updater instance
	 */
	public static function register( Config $config ): Plugin_Updater {
		$plugin_basename = $config->get_plugin_basename();
		
		if ( isset( self::$instances[ $plugin_basename ] ) ) {
			\_doing_it_wrong(
				__METHOD__,
				\sprintf( 'An updater for "%s" is already registered.', \esc_html( $plugin_basename ) ),
				'1.0.0'
			);
			
			return self::$instances[ $plugin_basename ];
		}
		
		self::$instances[ $plugin_basename ] = new Plugin_Updater( $config );
		
		return self::$instances[ $plugin_basename ];
	}
	
	/**
	 * Remove all registered updaters.
	 * 
	 * @internal	For tests only.
	 */
	public static function reset(): void {
		self::$instances = [];
	}
	
	/**
	 * Register an updater instance directly.
	 * 
	 * @internal	For tests and host-side dependency injection.
	 * 
	 * @param	string									$plugin_basename Plugin basename
	 * @param	\epiphyt\Plugin_Updater\Plugin_Updater	$updater Updater instance
	 */
	public static function set( string $plugin_basename, Plugin_Updater $updater ): void {
		self::$instances[ $plugin_basename ] = $updater;
	}
}
