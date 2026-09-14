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
		
		$directory = \dirname( $this->config->get_plugin_basename() );
		
		// a single-file plugin lives in the plugin directory itself and must not
		// be wrapped into a directory of its own
		if ( $directory === '.' || $directory === '' ) {
			return $source;
		}
		
		// WP_Upgrader derives the destination from basename( $source ), so
		// renaming the extracted directory is all that is needed
		if ( \basename( \untrailingslashit( $source ) ) === $directory ) {
			return $source;
		}
		
		$new_source = \trailingslashit( $remote_source ) . $directory;
		
		if ( $wp_filesystem->exists( $new_source ) && ! $wp_filesystem->delete( $new_source, true ) ) {
			return $source;
		}
		
		if ( ! $this->relocate( $source, $new_source, $remote_source ) ) {
			return $source;
		}
		
		// the cached metadata describes the version that has just been replaced
		$this->update_checker->invalidate_cache();
		
		return \trailingslashit( $new_source );
	}
	
	/**
	 * Move the extracted files to a new location inside the working directory.
	 * 
	 * A ZIP whose files sit at its root has no directory to rename: WP_Upgrader
	 * then uses the working directory itself as the source, and a directory
	 * cannot be renamed into one of its own children. In that case the entries
	 * are moved one by one instead.
	 * 
	 * @param	string	$source Current source location
	 * @param	string	$new_source Target source location
	 * @param	string	$remote_source Remote source location
	 * @return	bool Whether the files have been moved
	 */
	private function relocate( string $source, string $new_source, string $remote_source ): bool {
		global $wp_filesystem;
		
		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return false;
		}
		
		if ( \untrailingslashit( $source ) !== \untrailingslashit( $remote_source ) ) {
			return $wp_filesystem->move( \untrailingslashit( $source ), $new_source );
		}
		
		$entries = $wp_filesystem->dirlist( $source );
		
		if ( ! \is_array( $entries ) || $entries === [] ) {
			return false;
		}
		
		if ( ! $wp_filesystem->mkdir( $new_source ) ) {
			return false;
		}
		
		foreach ( \array_keys( $entries ) as $entry ) {
			$moved = $wp_filesystem->move(
				\trailingslashit( $source ) . $entry,
				\trailingslashit( $new_source ) . $entry
			);
			
			if ( ! $moved ) {
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * Register all hooks.
	 */
	public function register_hooks(): void {
		\add_filter( 'upgrader_source_selection', [ $this, 'move' ], 5, 4 );
	}
}
