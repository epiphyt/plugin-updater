<?php
declare(strict_types = 1);

namespace epiphyt\Plugin_Updater;

/**
 * Mapper turning update server metadata into what WordPress expects.
 * 
 * @author	Epiphyt
 * @license	GPL2
 * @package	epiphyt\Plugin_Updater
 */
final class Update_Response {
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
	 * Build the download URL.
	 * 
	 * The update server only embeds the credentials when the license validates,
	 * so they are appended here as a fallback.
	 * 
	 * @param	array<string, mixed>	$metadata Update server metadata
	 * @return	string Download URL
	 */
	private function get_download_url( array $metadata ): string {
		$download_url = \is_string( $metadata['download_url'] ?? null ) ? $metadata['download_url'] : '';
		
		if ( $download_url === '' ) {
			return '';
		}
		
		if ( $this->config->is_debug() ) {
			$download_url = \str_replace( '.zip', '-dev.zip', $download_url );
		}
		
		if ( self::has_credentials_parameters( $download_url ) ) {
			return $download_url;
		}
		
		$credentials = $this->license->get_credentials();
		
		if ( $credentials === null || ! $credentials->is_complete() ) {
			return $download_url;
		}
		
		$args = \array_merge( $credentials->to_request_args(), [
			'platform' => $this->config->get_platform(),
			'product_id' => $this->config->get_product_id(),
		] );
		
		// rawurlencode since add_query_arg() does not encode values
		return \add_query_arg( \array_map( 'rawurlencode', $args ), $download_url );
	}
	
	/**
	 * Get the formatted author of a plugin.
	 * 
	 * @param	array<string, mixed>	$metadata Update server metadata
	 * @return	string Formatted author
	 */
	public function get_formatted_author( array $metadata ): string {
		$author = \is_string( $metadata['author'] ?? null ) ? $metadata['author'] : '';
		$homepage = \is_string( $metadata['author_homepage'] ?? null ) ? $metadata['author_homepage'] : '';
		
		if ( $author === '' ) {
			return '';
		}
		
		if ( $homepage === '' ) {
			return \esc_html( $author );
		}
		
		return \sprintf(
			'<a href="%1$s">%2$s</a>',
			\esc_url( $homepage ),
			\esc_html( $author )
		);
	}
	
	/**
	 * Build the plugin information object for the plugins_api filter.
	 * 
	 * @param	array<string, mixed>	$metadata Update server metadata
	 * @return	\stdClass Plugin information
	 */
	public function to_information( array $metadata ): \stdClass {
		$information = new \stdClass();
		$information->author = $this->get_formatted_author( $metadata );
		$information->homepage = \is_string( $metadata['homepage'] ?? null ) ? $metadata['homepage'] : '';
		$information->name = \is_string( $metadata['name'] ?? null ) ? $metadata['name'] : $this->config->get_product_name();
		$information->requires = \is_string( $metadata['requires'] ?? null ) ? $metadata['requires'] : '';
		$information->sections = \is_array( $metadata['sections'] ?? null ) ? $metadata['sections'] : [];
		$information->slug = $this->config->get_plugin_slug();
		$information->tested = self::get_tested_version( $metadata );
		$information->version = \is_string( $metadata['version'] ?? null ) ? $metadata['version'] : '';
		
		if ( ! empty( $metadata['banners'] ) ) {
			$banners = \is_object( $metadata['banners'] ) ? \get_object_vars( $metadata['banners'] ) : $metadata['banners'];
			
			if ( \is_array( $banners ) ) {
				$information->banners = \array_intersect_key( $banners, [
					'high' => true,
					'low' => true,
				] );
			}
		}
		
		return $information;
	}
	
	/**
	 * Build the update object for the update_plugins transient.
	 * 
	 * @param	array<string, mixed>	$metadata Update server metadata
	 * @return	\stdClass Update object
	 */
	public function to_update( array $metadata ): \stdClass {
		$version = \is_string( $metadata['version'] ?? null ) ? $metadata['version'] : '';
		$update = new \stdClass();
		$update->icons = \is_array( $metadata['icons'] ?? null ) ? $metadata['icons'] : [];
		$update->new_version = $version;
		$update->package = $this->get_download_url( $metadata );
		$update->plugin = $this->config->get_plugin_basename();
		$update->requires = \is_string( $metadata['requires'] ?? null ) ? $metadata['requires'] : '';
		$update->slug = $this->config->get_plugin_slug();
		$update->tested = self::get_tested_version( $metadata );
		$update->url = \is_string( $metadata['homepage'] ?? null ) ? $metadata['homepage'] : '';
		$update->version = $version;
		
		if ( $version !== '' && $this->license->is_expired( $version ) ) {
			$update->upgrade_notice = $this->config->get_string( Strings::UPGRADE_NOTICE );
		}
		
		return $update;
	}
	
	/**
	 * Get the tested version.
	 * 
	 * WordPress compares this against its own version, so a patch level is
	 * appended – but only once.
	 * 
	 * @param	array<string, mixed>	$metadata Update server metadata
	 * @return	string Tested version
	 */
	private static function get_tested_version( array $metadata ): string {
		$tested = \is_string( $metadata['tested'] ?? null ) ? $metadata['tested'] : '';
		
		if ( $tested === '' || \str_ends_with( $tested, '.99' ) ) {
			return $tested;
		}
		
		return $tested . '.99';
	}
	
	/**
	 * Check whether a URL already carries credentials.
	 * 
	 * @param	string	$url URL to check
	 * @return	bool Whether the URL carries credentials
	 */
	private static function has_credentials_parameters( string $url ): bool {
		$query_string = \wp_parse_url( $url, \PHP_URL_QUERY );
		
		if ( ! \is_string( $query_string ) ) {
			return false;
		}
		
		$queries = [];
		
		\wp_parse_str( $query_string, $queries );
		
		return isset( $queries['email'], $queries['license_key'] );
	}
}
