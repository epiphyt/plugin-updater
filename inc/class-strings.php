<?php
declare(strict_types = 1);

namespace epiphyt\Plugin_Updater;

/**
 * Translated strings, injected by the host plugin.
 * 
 * This package intentionally has no text domain of its own. Every user-facing
 * string is translated inside the host plugin – with the host’s text domain –
 * and passed in here. That is why the updater must not be initialized before
 * the ‘init’ action: translation data is not available any earlier.
 * 
 * @author	Epiphyt
 * @license	GPL2
 * @package	epiphyt\Plugin_Updater
 */
final class Strings {
	public const ACTIVATION_FAILED_NOTICE = 'activation_failed_notice';
	
	public const ACTIVATION_FAILED_ROW = 'activation_failed_row';
	
	public const ACTIVATION_FAILED_ROW_HINT = 'activation_failed_row_hint';
	
	public const DEACTIVATION_FAILED_NOTICE = 'deactivation_failed_notice';
	
	public const EDIT_CREDENTIALS = 'edit_credentials';
	
	public const EXPIRED_NOTICE = 'expired_notice';
	
	public const INVALID_REQUEST = 'invalid_request';
	
	public const MISSING_CREDENTIALS = 'missing_credentials';
	
	public const RENEWAL_LINK_TEXT = 'renewal_link_text';
	
	public const RENEWAL_URL = 'renewal_url';
	
	public const UNKNOWN_ERROR = 'unknown_error';
	
	public const UPGRADE_NOTICE = 'upgrade_notice';
	
	private const DEFAULTS = [
		self::ACTIVATION_FAILED_NOTICE => 'License activation of %s failed:',
		self::ACTIVATION_FAILED_ROW => 'License activation of %s failed.',
		self::ACTIVATION_FAILED_ROW_HINT => 'You can’t download new versions of %s unless the license has been activated.',
		self::DEACTIVATION_FAILED_NOTICE => 'License deactivation of %s failed:',
		self::EDIT_CREDENTIALS => 'Edit license credentials',
		self::EXPIRED_NOTICE => 'Your update license has expired. Please visit %s for renewal.',
		self::INVALID_REQUEST => 'Invalid request.',
		self::MISSING_CREDENTIALS => 'Missing email address or license key.',
		self::RENEWAL_LINK_TEXT => 'Epiphyt',
		self::RENEWAL_URL => 'https://epiph.yt/en/',
		self::UNKNOWN_ERROR => 'Unknown error.',
		self::UPGRADE_NOTICE => 'Your update license has expired. Please visit https://epiph.yt/en/ for renewal.',
	];
	
	/**
	 * @var		array<string, string> Strings by key
	 */
	private array $strings = [];
	
	/**
	 * Class constructor.
	 * 
	 * @param	array<string, string>	$strings Translated strings, keyed by a class constant
	 */
	public function __construct( array $strings = [] ) {
		$this->strings = self::DEFAULTS;
		
		foreach ( $strings as $key => $string ) {
			if ( ! isset( self::DEFAULTS[ $key ] ) ) {
				\_doing_it_wrong(
					__METHOD__,
					\sprintf( 'Unknown string key "%s".', \esc_html( $key ) ),
					'1.0.0'
				);
				
				continue;
			}
			
			$this->strings[ $key ] = $string;
		}
	}
	
	/**
	 * Get all strings.
	 * 
	 * @return	array<string, string> All strings by key
	 */
	public function all(): array {
		return $this->strings;
	}
	
	/**
	 * Get a single string.
	 * 
	 * @param	string	$key String key, one of the class constants
	 * @return	string The string, or an empty string if the key is unknown
	 */
	public function get( string $key ): string {
		return $this->strings[ $key ] ?? '';
	}
	
	/**
	 * Get a new instance with additional strings merged in.
	 * 
	 * @param	array<string, string>	$strings Translated strings, keyed by a class constant
	 * @return	self New instance
	 */
	public function with( array $strings ): self {
		return new self( \array_merge( $this->strings, $strings ) );
	}
}
