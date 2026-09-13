<?php
declare(strict_types = 1);

namespace epiphyt\Plugin_Updater;

/**
 * License credentials.
 * 
 * @author	Epiphyt
 * @license	GPL2
 * @package	epiphyt\Plugin_Updater
 */
final class Credentials {
	/**
	 * @var		string Email address of the license owner
	 */
	private string $email = '';
	
	/**
	 * @var		string License key
	 */
	private string $license_key = '';
	
	/**
	 * Class constructor.
	 * 
	 * @param	string	$email Email address of the license owner
	 * @param	string	$license_key License key
	 */
	public function __construct( string $email, string $license_key ) {
		$this->email = $email;
		$this->license_key = $license_key;
	}
	
	/**
	 * Get credentials from wp-config constants.
	 * 
	 * @param	string	$email_constant Name of the email constant
	 * @param	string	$key_constant Name of the license key constant
	 * @return	self|null Credentials, or null if either constant is undefined
	 */
	public static function from_constants( string $email_constant, string $key_constant ): ?self {
		if ( ! \defined( $email_constant ) || ! \defined( $key_constant ) ) {
			return null;
		}
		
		$email = \constant( $email_constant );
		$license_key = \constant( $key_constant );
		
		if ( ! \is_string( $email ) || ! \is_string( $license_key ) ) {
			return null;
		}
		
		return new self( $email, $license_key );
	}
	
	/**
	 * Get credentials from a stored license option value.
	 * 
	 * Supports both the 'license_email' key used by the settings form and the
	 * 'email' key used on the wire.
	 * 
	 * @param	mixed	$value Raw license option value
	 * @return	self|null Credentials, or null if the value is unusable
	 */
	public static function from_option_value( mixed $value ): ?self {
		if ( ! \is_array( $value ) ) {
			return null;
		}
		
		$email = $value['license_email'] ?? $value['email'] ?? '';
		$license_key = $value['license_key'] ?? '';
		
		if ( ! \is_string( $email ) || ! \is_string( $license_key ) ) {
			return null;
		}
		
		if ( $email === '' && $license_key === '' ) {
			return null;
		}
		
		return new self( $email, $license_key );
	}
	
	/**
	 * Get credentials from a submitted option value.
	 * 
	 * The license key input is masked with asterisks once stored, so a submitted
	 * key containing an asterisk falls back to the currently stored key.
	 * 
	 * @param	mixed	$submitted Submitted (sanitized) option value
	 * @param	mixed	$stored Currently stored option value
	 * @return	self|null Credentials, or null if the values are unusable
	 */
	public static function from_submission( mixed $submitted, mixed $stored ): ?self {
		$credentials = self::from_option_value( $submitted );
		
		if ( $credentials === null ) {
			return null;
		}
		
		if ( \str_contains( $credentials->license_key, '*' ) ) {
			$stored_credentials = self::from_option_value( $stored );
			$stored_key = '';
			
			if ( $stored_credentials instanceof self ) {
				$stored_key = $stored_credentials->license_key;
			}
			
			$credentials = new self( $credentials->email, $stored_key );
		}
		
		return $credentials;
	}
	
	/**
	 * Get the email address.
	 * 
	 * @return	string Email address
	 */
	public function get_email(): string {
		return $this->email;
	}
	
	/**
	 * Get the license key.
	 * 
	 * @return	string License key
	 */
	public function get_license_key(): string {
		return $this->license_key;
	}
	
	/**
	 * Check whether both email address and license key are set.
	 * 
	 * @return	bool Whether the credentials are complete
	 */
	public function is_complete(): bool {
		return $this->email !== '' && $this->license_key !== '';
	}
	
	/**
	 * Get the credentials as request arguments.
	 * 
	 * Only these two values are ever sent. The stored license option may contain
	 * additional keys, which must not leak into outgoing requests.
	 * 
	 * @return	array{email: string, license_key: string} Request arguments
	 */
	public function to_request_args(): array {
		return [
			'email' => $this->email,
			'license_key' => $this->license_key,
		];
	}
}
