<?php
declare(strict_types = 1);

namespace epiphyt\Plugin_Updater;

/**
 * HTTP client for the update server.
 * 
 * This is the default implementation, talking to wp-update-server. Pass your own
 * Update_Client_Interface implementation to Config to use a different server.
 * 
 * @author	Epiphyt
 * @license	GPL2
 * @package	epiphyt\Plugin_Updater
 */
final class Update_Client implements Update_Client_Interface {
	/**
	 * @var		\epiphyt\Plugin_Updater\Config Plugin configuration
	 */
	private Config $config;
	
	/**
	 * @var		\epiphyt\Plugin_Updater\License License handler
	 */
	private License $license;
	
	/**
	 * Class constructor.
	 * 
	 * @param	\epiphyt\Plugin_Updater\Config	$config Plugin configuration
	 * @param	\epiphyt\Plugin_Updater\License	$license License handler
	 */
	public function __construct( Config $config, License $license ) {
		$this->config = $config;
		$this->license = $license;
	}
	
	/**
	 * {@inheritDoc}
	 */
	public function get_metadata(): array|\WP_Error {
		$response = $this->request( [
			'action' => 'get_metadata',
			'slug' => $this->config->get_update_slug(),
		] );
		
		if ( $response instanceof \WP_Error ) {
			return $response;
		}
		
		$data = \json_decode( $response, true );
		
		if ( ! \is_array( $data ) ) {
			return new \WP_Error(
				'epiphyt_plugin_updater_invalid_response',
				'The update server returned a response that is not valid JSON.'
			);
		}
		
		/** @var array<string, mixed> $data */
		return $data;
	}
	
	/**
	 * Perform a request against the update server.
	 * 
	 * @param	array<string, string>	$args Request arguments
	 * @return	string|\WP_Error Response body, or an error
	 */
	public function request( array $args ): string|\WP_Error {
		$credentials = $this->license->get_credentials();
		
		if ( $credentials === null || ! $credentials->is_complete() ) {
			return new \WP_Error(
				'epiphyt_plugin_updater_missing_credentials',
				$this->config->get_string( Strings::MISSING_CREDENTIALS )
			);
		}
		
		$args = \array_merge( $args, $credentials->to_request_args(), [
			'installed_version' => $this->config->get_installed_version(),
			'locale' => \get_locale(),
			'php' => \PHP_VERSION,
			'platform' => $this->config->get_platform(),
			'product_id' => $this->config->get_product_id(),
		] );
		$request = \wp_remote_post( $this->config->get_update_url(), [
			'body' => $args,
			'timeout' => $this->config->get_request_timeout(),
			'user-agent' => $this->config->get_user_agent(),
		] );
		
		if ( \is_wp_error( $request ) ) {
			return $request;
		}
		
		$response_code = \wp_remote_retrieve_response_code( $request );
		
		if ( $response_code < 200 || $response_code >= 300 ) {
			return new \WP_Error(
				'epiphyt_plugin_updater_unexpected_response_code',
				\sprintf( 'The update server responded with status code %d.', (int) $response_code )
			);
		}
		
		return \wp_remote_retrieve_body( $request );
	}
}
