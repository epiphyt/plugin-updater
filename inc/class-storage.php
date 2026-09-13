<?php
declare(strict_types = 1);

namespace epiphyt\Plugin_Updater;

/**
 * Multisite-aware storage for options and transients.
 * 
 * This is the single place where the decision between site and network storage
 * is made. Everything else in this package goes through it.
 * 
 * @author	Epiphyt
 * @license	GPL2
 * @package	epiphyt\Plugin_Updater
 */
final class Storage {
	public const KEY_DEACTIVATION_RESPONSE = 'deactivation_response';
	
	public const KEY_LICENSE_RESPONSE = 'license_response';
	
	public const KEY_UPDATE = 'update';
	
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
	 * Add an option without overwriting an existing value.
	 * 
	 * Used by the migration, which must never clobber newer data.
	 * 
	 * @param	string	$name Option name
	 * @param	mixed	$value Option value
	 * @return	bool Whether the option has been added
	 */
	public function add_option( string $name, mixed $value ): bool {
		if ( \is_multisite() ) {
			return \add_site_option( $name, $value );
		}
		
		return \add_option( $name, $value, '', false );
	}
	
	/**
	 * Delete an option.
	 * 
	 * @param	string	$name Option name
	 * @return	bool Whether the option has been deleted
	 */
	public function delete_option( string $name ): bool {
		if ( \is_multisite() ) {
			return \delete_site_option( $name );
		}
		
		return \delete_option( $name );
	}
	
	/**
	 * Delete a transient.
	 * 
	 * @param	string	$name Transient name
	 * @return	bool Whether the transient has been deleted
	 */
	public function delete_transient( string $name ): bool {
		if ( \is_multisite() ) {
			return \delete_site_transient( $name );
		}
		
		return \delete_transient( $name );
	}
	
	/**
	 * Get an option.
	 * 
	 * @param	string	$name Option name
	 * @param	mixed	$default_value Value to return if the option does not exist
	 * @return	mixed Option value
	 */
	public function get_option( string $name, mixed $default_value = false ): mixed {
		if ( \is_multisite() ) {
			return \get_site_option( $name, $default_value );
		}
		
		return \get_option( $name, $default_value );
	}
	
	/**
	 * Get an option as an array.
	 * 
	 * @param	string	$name Option name
	 * @param	string	$legacy_key Logical key whose legacy names are read as a fallback
	 * @return	array<string, mixed> Option value
	 */
	public function get_option_array( string $name, string $legacy_key = '' ): array {
		$value = $legacy_key !== '' ? $this->get_option_with_fallback( $name, $legacy_key ) : $this->get_option( $name );
		
		return \is_array( $value ) ? $value : [];
	}
	
	/**
	 * Get an option, falling back to its legacy names when it does not exist.
	 * 
	 * This read-through fallback is what makes the migration optional for
	 * correctness: the data is found either way.
	 * 
	 * @param	string	$name Option name
	 * @param	string	$legacy_key Logical key whose legacy names are read as a fallback
	 * @return	mixed Option value
	 */
	public function get_option_with_fallback( string $name, string $legacy_key ): mixed {
		$value = $this->get_option( $name, null );
		
		if ( $value !== null ) {
			return $value;
		}
		
		foreach ( $this->config->get_legacy_option_names( $legacy_key ) as $legacy_name ) {
			$legacy_value = $this->get_option( $legacy_name, null );
			
			if ( $legacy_value !== null ) {
				return $legacy_value;
			}
		}
		
		return false;
	}
	
	/**
	 * Get a transient.
	 * 
	 * @param	string	$name Transient name
	 * @return	mixed Transient value
	 */
	public function get_transient( string $name ): mixed {
		if ( \is_multisite() ) {
			return \get_site_transient( $name );
		}
		
		return \get_transient( $name );
	}
	
	/**
	 * Check whether an option exists.
	 * 
	 * Distinguishes a missing option from one holding an empty value, which the
	 * migration needs since an empty string is a value the old code wrote.
	 * 
	 * @param	string	$name Option name
	 * @return	bool Whether the option exists
	 */
	public function has_option( string $name ): bool {
		return $this->get_option( $name, null ) !== null;
	}
	
	/**
	 * Set a transient.
	 * 
	 * @param	string	$name Transient name
	 * @param	mixed	$value Transient value
	 * @param	int		$expiration Time until expiration in seconds
	 * @return	bool Whether the transient has been set
	 */
	public function set_transient( string $name, mixed $value, int $expiration ): bool {
		if ( \is_multisite() ) {
			return \set_site_transient( $name, $value, $expiration );
		}
		
		return \set_transient( $name, $value, $expiration );
	}
	
	/**
	 * Update an option.
	 * 
	 * @param	string	$name Option name
	 * @param	mixed	$value Option value
	 * @return	bool Whether the option has been updated
	 */
	public function update_option( string $name, mixed $value ): bool {
		if ( \is_multisite() ) {
			return \update_site_option( $name, $value );
		}
		
		return \update_option( $name, $value, false );
	}
}
