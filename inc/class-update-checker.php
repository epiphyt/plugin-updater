<?php
declare(strict_types = 1);

namespace epiphyt\Plugin_Updater;

/**
 * Update checker.
 * 
 * @author	Epiphyt
 * @license	GPL2
 * @package	epiphyt\Plugin_Updater
 */
final class Update_Checker {
	private const METADATA_CACHE_LIFETIME = 3600;
	
	private const REQUEST_THROTTLE = 10;
	
	/**
	 * @var		\epiphyt\Plugin_Updater\Update_Client_Interface Update server client
	 */
	private Update_Client_Interface $client;
	
	/**
	 * @var		\epiphyt\Plugin_Updater\Config Plugin configuration
	 */
	private Config $config;
	
	/**
	 * @var		\epiphyt\Plugin_Updater\Update_Response Update response mapper
	 */
	private Update_Response $response;
	
	/**
	 * @var		\epiphyt\Plugin_Updater\Storage Storage handler
	 */
	private Storage $storage;
	
	/**
	 * Class constructor.
	 * 
	 * @param	\epiphyt\Plugin_Updater\Config			$config Plugin configuration
	 * @param	\epiphyt\Plugin_Updater\Storage			$storage Storage handler
	 * @param	\epiphyt\Plugin_Updater\Update_Client_Interface	$client Update server client
	 * @param	\epiphyt\Plugin_Updater\Update_Response	$response Update response mapper
	 */
	public function __construct( Config $config, Storage $storage, Update_Client_Interface $client, Update_Response $response ) {
		$this->client = $client;
		$this->config = $config;
		$this->response = $response;
		$this->storage = $storage;
	}
	
	/**
	 * Add update data of this plugin to the update transient.
	 * 
	 * @param	mixed	$data Current update data
	 * @return	mixed Updated update data
	 */
	public function check_updates( mixed $data ): mixed {
		// compatibility with plugins filtering this value with something else
		if ( ! $data instanceof \stdClass ) {
			return $data;
		}
		
		$metadata = $this->get_metadata();
		
		if ( $metadata === null ) {
			return $data;
		}
		
		$update = $this->response->to_update( $metadata );
		$basename = $this->config->get_plugin_basename();
		
		if ( ! isset( $data->no_update ) || ! \is_array( $data->no_update ) ) {
			$data->no_update = [];
		}
		
		if ( ! isset( $data->response ) || ! \is_array( $data->response ) ) {
			$data->response = [];
		}
		
		if ( $this->has_update( $update->new_version ) ) {
			$data->response[ $basename ] = $update;
			
			unset( $data->no_update[ $basename ] );
		}
		else {
			$data->no_update[ $basename ] = $update;
			
			unset( $data->response[ $basename ] );
		}
		
		return $data;
	}
	
	/**
	 * Get the metadata of this plugin, cached.
	 * 
	 * @return	array<string, mixed>|null Metadata, or null if unavailable
	 */
	public function get_metadata(): ?array {
		if ( ! $this->is_force_check() ) {
			$cached = $this->storage->get_transient( $this->config->get_update_transient_name() );
			
			if ( \is_array( $cached ) ) {
				/** @var array<string, mixed> $cached */
				return $cached;
			}
			
			if ( $this->storage->get_transient( $this->config->get_request_time_transient_name() ) ) {
				return null;
			}
		}
		
		$this->storage->set_transient(
			$this->config->get_request_time_transient_name(),
			\time(),
			self::REQUEST_THROTTLE
		);
		$metadata = $this->client->get_metadata();
		
		if ( $metadata instanceof \WP_Error ) {
			return null;
		}
		
		$this->storage->set_transient(
			$this->config->get_update_transient_name(),
			$metadata,
			self::METADATA_CACHE_LIFETIME
		);
		
		return $metadata;
	}
	
	/**
	 * Provide plugin information for the plugin details screen.
	 * 
	 * @param	mixed	$data Current plugin information
	 * @param	string	$action Requested action
	 * @param	mixed	$args Request arguments
	 * @return	mixed Plugin information
	 */
	public function get_info( mixed $data, string $action, mixed $args ): mixed {
		if ( $action !== 'plugin_information' ) {
			return $data;
		}
		
		if ( ! \is_object( $args ) || ! \is_string( $args->slug ?? null ) ) {
			return $data;
		}
		
		if ( $args->slug !== $this->config->get_plugin_slug() ) {
			return $data;
		}
		
		$metadata = $this->get_metadata();
		
		if ( $metadata === null ) {
			return $data;
		}
		
		return $this->response->to_information( $metadata );
	}
	
	/**
	 * Check whether the offered version is newer than the installed one.
	 * 
	 * @param	string	$new_version Offered version
	 * @return	bool Whether an update is available
	 */
	public function has_update( string $new_version ): bool {
		$installed_version = $this->config->get_installed_version();
		
		if ( $new_version === '' || $installed_version === '' ) {
			return false;
		}
		
		return \version_compare( $installed_version, $new_version, '<' );
	}
	
	/**
	 * Check whether the user explicitly requested a fresh check.
	 * 
	 * Evaluated before any throttling, so the button in the admin always works.
	 * 
	 * @return	bool Whether a fresh check was requested
	 */
	private function is_force_check(): bool {
		return ! empty( $_GET['force-check'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
	
	/**
	 * Remove the cached metadata.
	 */
	public function invalidate_cache(): void {
		$this->storage->delete_transient( $this->config->get_update_transient_name() );
	}
	
	/**
	 * Register all hooks.
	 */
	public function register_hooks(): void {
		\add_filter( 'plugins_api', [ $this, 'get_info' ], 10, 3 );
		\add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_updates' ] );
	}
}
