<?php
declare(strict_types = 1);

namespace epiphyt\Plugin_Updater;

/**
 * HTTP client for the license server.
 * 
 * This is the default implementation, talking to the WooCommerce Software Add-on
 * API. Pass your own License_Client_Interface implementation to Config to use a
 * different license server.
 * 
 * Several servers can be configured; the first one returning a usable payload
 * wins and the rest are not contacted, unlike the original implementation which
 * always queried every server even after a success.
 * 
 * @author	Epiphyt
 * @license	GPL2
 * @package	epiphyt\Plugin_Updater
 */
final class License_Client implements License_Client_Interface {
	public const REQUEST_ACTIVATION = 'activation';
	
	public const REQUEST_CHECK = 'check';
	
	public const REQUEST_DEACTIVATION = 'deactivation';
	
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
	 * {@inheritDoc}
	 */
	public function activate( Credentials $credentials ): array|\WP_Error {
		return $this->request( self::REQUEST_ACTIVATION, $credentials );
	}
	
	/**
	 * {@inheritDoc}
	 */
	public function check( Credentials $credentials ): array|\WP_Error {
		return $this->request( self::REQUEST_CHECK, $credentials );
	}
	
	/**
	 * {@inheritDoc}
	 */
	public function deactivate( Credentials $credentials ): array|\WP_Error {
		return $this->request( self::REQUEST_DEACTIVATION, $credentials );
	}
	
	/**
	 * Check whether a response is usable.
	 * 
	 * @param	mixed	$response Decoded response
	 * @return	bool Whether the response is usable
	 */
	private function is_usable_response( mixed $response ): bool {
		if ( ! \is_array( $response ) ) {
			return false;
		}
		
		if ( isset( $response['activations'] ) && \is_array( $response['activations'] ) ) {
			return true;
		}
		
		if ( ! empty( $response['activated'] ) ) {
			return true;
		}
		
		return isset( $response['reset'] );
	}
	
	/**
	 * Perform a request against the license servers.
	 * 
	 * @param	string								$type Request type, one of the REQUEST_* constants
	 * @param	\epiphyt\Plugin_Updater\Credentials	$credentials License credentials
	 * @return	array<string, mixed>|\WP_Error Response, or an error
	 */
	private function request( string $type, Credentials $credentials ): array|\WP_Error {
		if ( ! $credentials->is_complete() ) {
			return new \WP_Error(
				'epiphyt_plugin_updater_missing_credentials',
				$this->config->get_string( Strings::MISSING_CREDENTIALS )
			);
		}
		
		$args = \array_merge( $credentials->to_request_args(), [
			'instance' => $this->config->get_instance_id(),
			'platform' => $this->config->get_platform(),
			'product_id' => $this->config->get_product_id(),
			'request' => $type,
		] );
		$last_error = null;
		$fallback = null;
		
		foreach ( $this->config->get_license_servers() as $url ) {
			$request = \wp_remote_get( $url, [
				'body' => $args,
				'timeout' => $this->config->get_request_timeout(),
				'user-agent' => $this->config->get_user_agent(),
			] );
			
			if ( \is_wp_error( $request ) ) {
				$last_error = $request;
				
				continue;
			}
			
			$response = \json_decode( \wp_remote_retrieve_body( $request ), true );
			
			if ( $this->is_usable_response( $response ) ) {
				/** @var array<string, mixed> $response */
				return $response;
			}
			
			if ( $fallback === null && \is_array( $response ) ) {
				/** @var array<string, mixed> $response */
				$fallback = $response;
			}
		}
		
		if ( $fallback !== null ) {
			return $fallback;
		}
		
		if ( $last_error instanceof \WP_Error ) {
			return $last_error;
		}
		
		return new \WP_Error(
			'epiphyt_plugin_updater_invalid_license_response',
			'No license server returned a usable response.'
		);
	}
}
