<?php
declare(strict_types = 1);

namespace epiphyt\Plugin_Updater;

/**
 * One-time migration of legacy option names.
 * 
 * This migration is a convenience, not a correctness requirement: Storage reads
 * legacy option names as a fallback anyway, and the stored license response is a
 * cache that the next check rebuilds. Copying it across merely avoids a spurious
 * “activation failed” notice right after an update.
 * 
 * Consequently the migration is deliberately lazy and non-destructive. It copies
 * instead of moving, never overwrites an existing value, and never touches the
 * option holding the license credentials.
 * 
 * @author	Epiphyt
 * @license	GPL2
 * @package	epiphyt\Plugin_Updater
 */
final class Migration {
	public const SCHEMA_VERSION = 1;
	
	/**
	 * @var		\epiphyt\Plugin_Updater\Config Plugin configuration
	 */
	private Config $config;
	
	/**
	 * Class constructor.
	 * 
	 * @param	\epiphyt\Plugin_Updater\Config	$config Plugin configuration
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}
	
	/**
	 * Copy a single option if the target does not exist yet.
	 * 
	 * @param	string	$target Target option name
	 * @param	string	$source Source option name
	 * @param	bool	$network Whether to operate on network options
	 * @return	bool Whether something has been copied
	 */
	private function copy( string $target, string $source, bool $network ): bool {
		if ( $this->get( $target, $network ) !== null ) {
			return false;
		}
		
		$value = $this->get( $source, $network );
		
		if ( $value === null ) {
			return false;
		}
		
		if ( $network ) {
			return \add_site_option( $target, $value );
		}
		
		return \add_option( $target, $value, '', false );
	}
	
	/**
	 * Get an option, distinguishing a missing option from an empty value.
	 * 
	 * @param	string	$name Option name
	 * @param	bool	$network Whether to operate on network options
	 * @return	mixed Option value, or null if the option does not exist
	 */
	private function get( string $name, bool $network ): mixed {
		if ( $network ) {
			$value = \get_network_option( null, $name, null );
		}
		else {
			$value = \get_option( $name, null );
		}
		
		return $value;
	}
	
	/**
	 * Get the map of target option names by logical key.
	 * 
	 * The option holding update data is deliberately absent: nothing reads it
	 * any more, and on the older layout it was shared between plugins, so
	 * copying it could make one plugin act on another plugin’s data.
	 * 
	 * @return	array<string, string> Target option names by logical key
	 */
	private function get_targets(): array {
		return [
			Storage::KEY_DEACTIVATION_RESPONSE => $this->config->get_deactivation_response_option_name(),
			Storage::KEY_LICENSE_RESPONSE => $this->config->get_license_response_option_name(),
		];
	}
	
	/**
	 * Check whether the migration should run at all.
	 * 
	 * Never runs on a front-end request: this writes options, and a visitor’s
	 * page load is the wrong place for that.
	 * 
	 * @return	bool Whether the migration may run
	 */
	private function is_allowed(): bool {
		if ( \defined( 'EPIPHYT_UPDATER_SKIP_MIGRATION' ) && \constant( 'EPIPHYT_UPDATER_SKIP_MIGRATION' ) ) {
			return false;
		}
		
		if ( $this->config->get_all_legacy_option_names() === [] ) {
			return false;
		}
		
		return \is_admin() || \wp_doing_cron() || ( \defined( 'WP_CLI' ) && \constant( 'WP_CLI' ) );
	}
	
	/**
	 * Run the migration.
	 * 
	 * Safe to call repeatedly: the schema marker short-circuits it, and every
	 * write is an add that cannot overwrite newer data even without the marker.
	 */
	public function migrate(): void {
		if ( ! $this->is_allowed() ) {
			return;
		}
		
		$this->migrate_scope( \is_multisite() );
	}
	
	/**
	 * Run the migration for a single storage scope.
	 * 
	 * Both scopes are migrated on a multisite, because the original libraries
	 * branched on is_network_admin() in some places and is_multisite() in
	 * others, so data can legitimately sit in either.
	 * 
	 * @param	bool	$network Whether to operate on network options
	 */
	private function migrate_scope( bool $network ): void {
		$schema_option = $this->config->get_schema_option_name();
		$schema_version = $this->get( $schema_option, $network );
		
		if ( \is_numeric( $schema_version ) && (int) $schema_version >= self::SCHEMA_VERSION ) {
			return;
		}
		
		$migrated = [];
		
		foreach ( $this->get_targets() as $key => $target ) {
			foreach ( $this->config->get_legacy_option_names( $key ) as $source ) {
				if ( $this->copy( $target, $source, $network ) ) {
					$migrated[ $target ] = $source;
					
					break;
				}
			}
		}
		
		if ( $network ) {
			\update_site_option( $schema_option, self::SCHEMA_VERSION );
			\update_site_option( $schema_option . '_migration', $this->get_breadcrumb( $migrated, true ) );
			
			return;
		}
		
		\update_option( $schema_option, self::SCHEMA_VERSION, false );
		\update_option( $schema_option . '_migration', $this->get_breadcrumb( $migrated, false ), false );
	}
	
	/**
	 * Build the breadcrumb recording what has been migrated.
	 * 
	 * @param	array<string, string>	$migrated Source option name by target option name
	 * @param	bool					$network Whether the migration ran on network options
	 * @return	array<string, mixed> Breadcrumb
	 */
	private function get_breadcrumb( array $migrated, bool $network ): array {
		return [
			'migrated' => $migrated,
			'network' => $network,
			'time' => \time(),
			'version' => self::SCHEMA_VERSION,
		];
	}
	
	/**
	 * Register all hooks.
	 */
	public function register_hooks(): void {
		\add_action( 'admin_init', [ $this, 'migrate' ], 0 );
		
		if ( \wp_doing_cron() || ( \defined( 'WP_CLI' ) && \constant( 'WP_CLI' ) ) ) {
			$this->migrate();
		}
	}
}
