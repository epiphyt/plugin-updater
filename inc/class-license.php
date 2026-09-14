<?php
declare(strict_types = 1);

namespace epiphyt\Plugin_Updater;

/**
 * License state machine.
 * 
 * @author	Epiphyt
 * @license	GPL2
 * @package	epiphyt\Plugin_Updater
 */
final class License {
	private const ERROR_RETRY_INTERVAL = 600;
	
	private const SUCCESS_INTERVAL = 3600;
	
	/**
	 * @var		\epiphyt\Plugin_Updater\License_Client_Interface License server client
	 */
	private License_Client_Interface $client;
	
	/**
	 * @var		\epiphyt\Plugin_Updater\Config Plugin configuration
	 */
	private Config $config;
	
	/**
	 * @var		bool Whether a check already ran in this request
	 */
	private bool $has_checked = false;
	
	/**
	 * @var		\epiphyt\Plugin_Updater\Storage Storage handler
	 */
	private Storage $storage;
	
	/**
	 * Class constructor.
	 * 
	 * @param	\epiphyt\Plugin_Updater\Config	$config Plugin configuration
	 * @param	\epiphyt\Plugin_Updater\Storage	$storage Storage handler
	 * @param	\epiphyt\Plugin_Updater\License_Client_Interface	$client License server client
	 */
	public function __construct( Config $config, Storage $storage, License_Client_Interface $client ) {
		$this->client = $client;
		$this->config = $config;
		$this->storage = $storage;
	}
	
	/**
	 * Activate the license for this installation.
	 * 
	 * @param	\epiphyt\Plugin_Updater\Credentials|null	$credentials License credentials, or null to use the stored ones
	 * @return	true|\WP_Error True on success, an error otherwise
	 */
	public function activate( ?Credentials $credentials = null ): bool|\WP_Error {
		$credentials ??= $this->get_credentials();
		
		if ( $credentials === null || ! $credentials->is_complete() ) {
			return new \WP_Error(
				'epiphyt_plugin_updater_missing_credentials',
				$this->config->get_string( Strings::MISSING_CREDENTIALS )
			);
		}
		
		$response = $this->client->activate( $credentials );
		
		if ( $response instanceof \WP_Error ) {
			$this->store_response( $this->to_error_response( $response->get_error_message() ) );
			
			return $response;
		}
		
		if ( ! empty( $response['error'] ) ) {
			$this->store_response( $response );
			
			return new \WP_Error(
				'epiphyt_plugin_updater_activation_failed',
				\is_string( $response['error'] ) ? $response['error'] : $this->config->get_string( Strings::UNKNOWN_ERROR )
			);
		}
		
		$this->refresh_response( $credentials );
		
		return true;
	}
	
	/**
	 * Check whether the license can be deactivated from here.
	 * 
	 * Deactivation only requires credentials, not an active license: a site
	 * whose activation was removed elsewhere must still be able to clear its
	 * local state. On a multisite, only the network admin may deactivate, since
	 * the license belongs to the whole network.
	 * 
	 * @return	bool Whether the license can be deactivated
	 */
	public function can_deactivate(): bool {
		if ( \is_multisite() && ! \is_network_admin() && ! ( \defined( 'WP_CLI' ) && \constant( 'WP_CLI' ) ) ) {
			return false;
		}
		
		$credentials = $this->get_credentials();
		
		return $credentials !== null && $credentials->is_complete() && $this->is_activated();
	}
	
	/**
	 * Check the license against the license server.
	 * 
	 * @param	bool	$force Whether to bypass all throttling
	 */
	public function check( bool $force = false ): void {
		if ( $this->has_checked && ! $force ) {
			return;
		}
		
		$credentials = $this->get_credentials();
		
		// a fresh installation has no credentials yet, so there is nothing to check
		if ( $credentials === null || ! $credentials->is_complete() ) {
			return;
		}
		
		if ( ! $force && $this->is_throttled() ) {
			return;
		}
		
		$this->has_checked = true;
		$response = $this->client->check( $credentials );
		
		if ( $response instanceof \WP_Error ) {
			$this->store_response( $this->to_error_response( $response->get_error_message() ) );
			
			return;
		}
		
		if ( empty( $response['success'] ) ) {
			$this->store_response( $this->to_error_response(
				\is_string( $response['error'] ?? null ) ? $response['error'] : ''
			) );
			
			return;
		}
		
		if ( ! $this->contains_own_instance( $response ) ) {
			$this->activate( $credentials );
			
			return;
		}
		
		$this->store_response( $response );
		$this->storage->update_option( $this->config->get_deactivation_response_option_name(), '' );
	}
	
	/**
	 * Check whether a license response contains this installation.
	 * 
	 * @param	array<string, mixed>	$response License response
	 * @return	bool Whether the response contains this installation
	 */
	private function contains_own_instance( array $response ): bool {
		if ( empty( $response['activations'] ) || ! \is_array( $response['activations'] ) ) {
			return false;
		}
		
		$own_instance = $this->config->get_instance_id();
		
		foreach ( $response['activations'] as $activation ) {
			if ( ! \is_array( $activation ) || ! \is_string( $activation['instance'] ?? null ) ) {
				continue;
			}
			
			if ( \trailingslashit( $activation['instance'] ) === $own_instance ) {
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * Deactivate the license for this installation.
	 * 
	 * @param	\epiphyt\Plugin_Updater\Credentials|null	$credentials License credentials, or null to use the stored ones
	 * @return	true|\WP_Error True on success, an error otherwise
	 */
	public function deactivate( ?Credentials $credentials = null ): bool|\WP_Error {
		$credentials ??= $this->get_credentials();
		
		if ( $credentials === null || ! $credentials->is_complete() ) {
			return new \WP_Error(
				'epiphyt_plugin_updater_invalid_request',
				$this->config->get_string( Strings::INVALID_REQUEST )
			);
		}
		
		$response = $this->client->deactivate( $credentials );
		
		if ( $response instanceof \WP_Error ) {
			$this->storage->update_option(
				$this->config->get_deactivation_response_option_name(),
				$this->to_error_response( $response->get_error_message() )
			);
			
			return $response;
		}
		
		if ( ! empty( $response['error'] ) ) {
			$this->storage->update_option( $this->config->get_deactivation_response_option_name(), $response );
			
			return new \WP_Error(
				'epiphyt_plugin_updater_deactivation_failed',
				\is_string( $response['error'] ) ? $response['error'] : $this->config->get_string( Strings::UNKNOWN_ERROR )
			);
		}
		
		$this->storage->update_option( $this->config->get_deactivation_response_option_name(), '' );
		$this->storage->delete_option( $this->config->get_license_response_option_name() );
		$this->unregister_option_hooks();
		
		return true;
	}
	
	/**
	 * Get the credentials to use.
	 * 
	 * @return	\epiphyt\Plugin_Updater\Credentials|null Credentials, or null if unavailable
	 */
	public function get_credentials(): ?Credentials {
		$credentials = Credentials::from_constants(
			$this->config->get_license_email_constant(),
			$this->config->get_license_key_constant()
		);
		
		if ( $credentials !== null ) {
			return $credentials;
		}
		
		return Credentials::from_option_value(
			$this->storage->get_option( $this->config->get_license_option_name() )
		);
	}
	
	/**
	 * Get the stored deactivation response.
	 * 
	 * @return	array<string, mixed> Deactivation response
	 */
	public function get_deactivation_response(): array {
		return $this->storage->get_option_array(
			$this->config->get_deactivation_response_option_name(),
			Storage::KEY_DEACTIVATION_RESPONSE
		);
	}
	
	/**
	 * Get the highest version covered by the license.
	 * 
	 * @return	string Highest licensed version, or an empty string
	 */
	public function get_maximum_licensed_version(): string {
		$response = $this->get_response();
		$maximum_version = '';
		
		if ( empty( $response['activations'] ) || ! \is_array( $response['activations'] ) ) {
			return '';
		}
		
		foreach ( $response['activations'] as $activation ) {
			if ( ! \is_array( $activation ) || ! \is_string( $activation['software_version'] ?? null ) ) {
				continue;
			}
			
			$version = $activation['software_version'];
			
			if ( $version === '' ) {
				continue;
			}
			
			if ( $maximum_version === '' || \version_compare( $version, $maximum_version, '>' ) ) {
				$maximum_version = $version;
			}
		}
		
		return $maximum_version;
	}
	
	/**
	 * Get the stored license response.
	 * 
	 * @return	array<string, mixed> License response
	 */
	public function get_response(): array {
		return $this->storage->get_option_array(
			$this->config->get_license_response_option_name(),
			Storage::KEY_LICENSE_RESPONSE
		);
	}
	
	/**
	 * Check whether the last license response failed.
	 * 
	 * @return	bool Whether the last response failed
	 */
	public function has_failed(): bool {
		return self::response_failed( $this->get_response() );
	}
	
	/**
	 * Check whether the last deactivation failed.
	 * 
	 * @return	bool Whether the last deactivation failed
	 */
	public function has_failed_deactivation(): bool {
		return self::response_failed( $this->get_deactivation_response() );
	}
	
	/**
	 * Check whether the license is active for this installation.
	 * 
	 * All three conditions must hold: complete credentials, a successful stored
	 * response, and an activation matching this installation.
	 * 
	 * @return	bool Whether the license is active
	 */
	public function is_activated(): bool {
		$credentials = $this->get_credentials();
		
		if ( $credentials === null || ! $credentials->is_complete() ) {
			return false;
		}
		
		$response = $this->get_response();
		
		if ( empty( $response['success'] ) ) {
			return false;
		}
		
		return $this->contains_own_instance( $response );
	}
	
	/**
	 * Check whether an update is outside the licensed version range.
	 * 
	 * @param	string	$new_version Version to check, or an empty string to use the pending update
	 * @return	bool Whether the license has expired for that version
	 */
	public function is_expired( string $new_version = '' ): bool {
		$maximum_version = $this->get_maximum_licensed_version();
		
		if ( $maximum_version === '' ) {
			return false;
		}
		
		if ( $new_version === '' ) {
			$new_version = $this->get_pending_update_version();
		}
		
		if ( $new_version === '' ) {
			return false;
		}
		
		return \version_compare(
			self::truncate_version( $new_version, $maximum_version ),
			$maximum_version,
			'>'
		);
	}
	
	/**
	 * Get the version of a pending update, if any.
	 * 
	 * @return	string Pending version, or an empty string
	 */
	private function get_pending_update_version(): string {
		$updates = \get_site_transient( 'update_plugins' );
		$basename = $this->config->get_plugin_basename();
		
		if ( ! \is_object( $updates ) || ! isset( $updates->response ) || ! \is_array( $updates->response ) ) {
			return '';
		}
		
		$update = $updates->response[ $basename ] ?? null;
		
		if ( ! \is_object( $update ) || ! \is_string( $update->new_version ?? null ) ) {
			return '';
		}
		
		return $update->new_version;
	}
	
	/**
	 * Check whether a license check is currently throttled.
	 * 
	 * @return	bool Whether the check should be skipped
	 */
	private function is_throttled(): bool {
		$response = $this->get_response();
		$timestamp = $response['timestamp'] ?? null;
		
		if ( ! \is_int( $timestamp ) ) {
			return false;
		}
		
		if (
			! empty( $response['success'] )
			&& empty( $response['error'] )
			&& $timestamp > \time() - self::SUCCESS_INTERVAL
		) {
			return true;
		}
		
		return \wp_doing_cron() && ! empty( $response['error'] ) && $timestamp > \time() - self::ERROR_RETRY_INTERVAL;
	}
	
	/**
	 * Handle the recurring license check.
	 */
	public function on_cron(): void {
		$this->check();
	}
	
	/**
	 * Handle a newly added license option.
	 * 
	 * @param	string	$option Option name
	 * @param	mixed	$value Option value
	 */
	public function on_option_added( string $option, mixed $value ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->check( true );
	}
	
	/**
	 * Handle an updated license option.
	 * 
	 * @param	mixed	$old_value Previous option value
	 * @param	mixed	$value New option value
	 */
	public function on_option_updated( mixed $old_value, mixed $value ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->check( true );
	}
	
	/**
	 * Handle a newly added or updated license site option.
	 * 
	 * @param	string	$option Option name
	 * @param	mixed	$value Option value
	 */
	public function on_site_option_changed( string $option, mixed $value ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->check( true );
	}
	
	/**
	 * Refresh the stored response from the license server.
	 * 
	 * Performed after an activation so the stored payload carries the real
	 * activation data.
	 * 
	 * @param	\epiphyt\Plugin_Updater\Credentials	$credentials License credentials
	 */
	private function refresh_response( Credentials $credentials ): void {
		$response = $this->client->check( $credentials );
		
		if ( $response instanceof \WP_Error ) {
			$this->store_response( $this->to_error_response( $response->get_error_message() ) );
			
			return;
		}
		
		$this->store_response( $response );
		$this->storage->update_option( $this->config->get_deactivation_response_option_name(), '' );
	}
	
	/**
	 * Register all hooks.
	 * 
	 * Each hook gets an adapter matching its own signature. WordPress passes
	 * different arguments for site options than for options, which the original
	 * implementation ignored.
	 */
	public function register_hooks(): void {
		$option_name = $this->config->get_license_option_name();
		
		\add_action( 'add_option_' . $option_name, [ $this, 'on_option_added' ], 10, 2 );
		\add_action( 'update_option_' . $option_name, [ $this, 'on_option_updated' ], 10, 2 );
		\add_action( 'add_site_option_' . $option_name, [ $this, 'on_site_option_changed' ], 10, 2 );
		\add_action( 'update_site_option_' . $option_name, [ $this, 'on_site_option_changed' ], 10, 2 );
		\add_action( $this->config->get_cron_hook(), [ $this, 'on_cron' ] );
	}
	
	/**
	 * Check whether a stored response represents a failure.
	 * 
	 * @param	array<string, mixed>	$response Stored response
	 * @return	bool Whether the response represents a failure
	 */
	public static function response_failed( array $response ): bool {
		// an absent response is not a failure – nothing has been attempted yet
		if ( $response === [] ) {
			return false;
		}
		
		return empty( $response['success'] );
	}
	
	/**
	 * Store a license response.
	 * 
	 * @param	array<string, mixed>	$response License response
	 */
	private function store_response( array $response ): void {
		$this->storage->update_option( $this->config->get_license_response_option_name(), $response );
	}
	
	/**
	 * Build an error response.
	 * 
	 * @param	string	$message Error message
	 * @return	array<string, mixed> Error response
	 */
	private function to_error_response( string $message ): array {
		return [
			'error' => $message !== '' ? $message : $this->config->get_string( Strings::UNKNOWN_ERROR ),
			'success' => false,
			'timestamp' => \time(),
		];
	}
	
	/**
	 * Truncate a version to the precision of a reference version.
	 * 
	 * Comparison happens per segment.
	 * 
	 * @param	string	$version Version to truncate
	 * @param	string	$reference Reference version defining the precision
	 * @return	string Truncated version
	 */
	public static function truncate_version( string $version, string $reference ): string {
		$segments = \explode( '.', $version );
		$precision = \count( \explode( '.', $reference ) );
		
		return \implode( '.', \array_slice( $segments, 0, $precision ) );
	}
	
	/**
	 * Remove the option hooks triggering a license check.
	 */
	private function unregister_option_hooks(): void {
		$option_name = $this->config->get_license_option_name();
		
		\remove_action( 'add_option_' . $option_name, [ $this, 'on_option_added' ], 10 );
		\remove_action( 'update_option_' . $option_name, [ $this, 'on_option_updated' ], 10 );
		\remove_action( 'add_site_option_' . $option_name, [ $this, 'on_site_option_changed' ], 10 );
		\remove_action( 'update_site_option_' . $option_name, [ $this, 'on_site_option_changed' ], 10 );
	}
}
