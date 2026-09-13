<?php
declare(strict_types = 1);

namespace epiphyt\Plugin_Updater;

/**
 * Renames the extracted package to the plugin directory name.
 * 
 * WordPress installs an update into a directory named after the extracted
 * folder, not after the plugin slug. The ZIP delivered by the update server
 * carries a generated name, so without this the update would land in the wrong
 * directory and the plugin would silently be installed twice.
 * 
 * @author	Epiphyt
 * @license	GPL2
 * @package	epiphyt\Plugin_Updater
 */
final class Package_Mover {
	/**
	 * @var		\epiphyt\Plugin_Updater\Config Plugin configuration
	 */
	private Config $config;
	
	/**
	 * @var		\epiphyt\Plugin_Updater\Update_Checker Update checker
	 */
	private Update_Checker $update_checker;
	
	/**
	 * Class constructor.
	 * 
	 * @param	\epiphyt\Plugin_Updater\Config			$config Plugin configuration
	 * @param	\epiphyt\Plugin_Updater\Update_Checker	$update_checker Update checker
	 */
	public function __construct( Config $config, Update_Checker $update_checker ) {
		$this->config = $config;
		$this->update_checker = $update_checker;
	}
	
	/**
	 * Move the extracted package into a directory matching the plugin slug.
	 * 
	 * @param	mixed	$source Current source location
	 * @param	mixed	$remote_source Remote source location
	 * @param	mixed	$upgrader Upgrader instance
	 * @param	mixed	$args Extra arguments
	 * @return	mixed Updated source location
	 */
	public function move( mixed $source, mixed $remote_source, mixed $upgrader, mixed $args ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		global $wp_filesystem;
		
		if ( ! \is_string( $source ) || ! \is_string( $remote_source ) ) {
			return $source;
		}
		
		if ( ! \is_array( $args ) || ( $args['plugin'] ?? '' ) !== $this->config->get_plugin_basename() ) {
			return $source;
		}
		
		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base || ! $wp_filesystem->exists( $remote_source ) ) {
			return $source;
		}
		
		$directory = $this->config->get_plugin_slug();
		
		// WP_Upgrader derives the destination from basename( $source ), so
		// renaming the extracted directory is all that is needed
		if ( \basename( \untrailingslashit( $source ) ) === $directory ) {
			return $source;
		}
		
		$new_source = \trailingslashit( $remote_source ) . $directory;
		
		if ( ! $wp_filesystem->move( \untrailingslashit( $source ), $new_source ) ) {
			return $source;
		}
		
		// the cached metadata describes the version that has just been replaced
		$this->update_checker->invalidate_cache();
		
		return \trailingslashit( $new_source );
	}
	
	/**
	 * Register all hooks.
	 */
	public function register_hooks(): void {
		\add_filter( 'upgrader_source_selection', [ $this, 'move' ], 5, 4 );
	}
}
