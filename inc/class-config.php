<?php
declare(strict_types = 1);

namespace epiphyt\Plugin_Updater;

/**
 * Immutable per-plugin configuration.
 * 
 * This object is pure: it performs no database access in its constructor, so it
 * can be created at any point in the request without depending on load order.
 * Every value that needs WordPress state is resolved lazily in its getter.
 * 
 * @author	Epiphyt
 * @license	GPL2
 * @package	epiphyt\Plugin_Updater
 */
final class Config {
	public const DEFAULT_UPDATE_URL = 'https://update.epiph.yt';
	
	public const DEBUG_UPDATE_URL = 'https://devupdate.epiph.yt';
	
	public const OPTION_PREFIX = 'epiphyt_updater_';
	
	private const DEFAULT_LICENSE_SERVERS = [
		'https://epiph.yt/wocommerce/?wc-api=software-api',
		'https://epiph.yt/en/wocommerce/?wc-api=software-api',
	];
	
	/**
	 * @var		string Cron hook name for the recurring license check
	 */
	private string $cron_hook = '';
	
	/**
	 * @var		array<string, string[]> Legacy option names to read from and migrate, by logical key
	 */
	private array $legacy_option_names = [];
	
	/**
	 * @var		(callable(\epiphyt\Plugin_Updater\Config): \epiphyt\Plugin_Updater\License_Client_Interface)|null Factory building the license server client
	 */
	private $license_client_factory = null;
	
	/**
	 * @var		string Name of the constant holding the license email address
	 */
	private string $license_email_constant = '';
	
	/**
	 * @var		string Name of the constant holding the license key
	 */
	private string $license_key_constant = '';
	
	/**
	 * @var		string Option name holding the license credentials
	 */
	private string $license_option_name = '';
	
	/**
	 * @var		string[] License server URLs
	 */
	private array $license_servers = [];
	
	/**
	 * @var		bool Whether this package schedules the cron event itself
	 */
	private bool $manages_cron = true;
	
	/**
	 * @var		string Plugin basename
	 */
	private string $plugin_basename = '';
	
	/**
	 * @var		string Key used for option names
	 */
	private string $plugin_key = '';
	
	/**
	 * @var		string Product ID on the license server
	 */
	private string $product_id = '';
	
	/**
	 * @var		string Display name of the product
	 */
	private string $product_name = '';
	
	/**
	 * @var		int Request timeout in seconds
	 */
	private int $request_timeout = 15;
	
	/**
	 * @var		string Value of the 'page' query argument of the settings page
	 */
	private string $settings_page_slug = '';
	
	/**
	 * @var		string|callable(): string License settings URL
	 */
	private $settings_url = '';
	
	/**
	 * @var		\epiphyt\Plugin_Updater\Strings Translated strings
	 */
	private Strings $strings;
	
	/**
	 * @var		string Package slug on the update server
	 */
	private string $update_slug = '';
	
	/**
	 * @var		(callable(\epiphyt\Plugin_Updater\Config, \epiphyt\Plugin_Updater\License): \epiphyt\Plugin_Updater\Update_Client_Interface)|null Factory building the update server client
	 */
	private $update_client_factory = null;
	
	/**
	 * @var		string Update server URL
	 */
	private string $update_url = '';
	
	/**
	 * Class constructor.
	 * 
	 * @param	string									$plugin_basename Plugin basename, e.g. 'my-plugin/my-plugin.php'
	 * @param	string									$plugin_key Key used for option names, e.g. 'my_plugin'
	 * @param	string									$product_id Product ID on the license server, e.g. 'My Plugin'
	 * @param	string									$update_slug Package slug on the update server
	 * @param	string									$license_option_name Option name holding the license credentials
	 * @param	string|callable(): string				$settings_url License settings URL
	 * @param	string									$settings_page_slug Value of the 'page' query argument of the settings page
	 * @param	\epiphyt\Plugin_Updater\Strings|null	$strings Translated strings
	 * @param	string									$product_name Display name, defaults to the product ID
	 * @param	string									$license_email_constant Name of the email constant
	 * @param	string									$license_key_constant Name of the license key constant
	 * @param	string									$cron_hook Cron hook name for the recurring license check
	 * @param	bool									$manages_cron Whether this package schedules the cron event itself
	 * @param	string									$update_url Update server URL
	 * @param	string[]								$license_servers License server URLs
	 * @param	int										$request_timeout Request timeout in seconds
	 * @param	array<string, string[]>					$legacy_option_names Legacy option names by logical key
	 * @param	class-string|callable|null				$update_client Update server client class or factory
	 * @param	class-string|callable|null				$license_client License server client class or factory
	 * @throws	\InvalidArgumentException If required data is missing or a client is unusable.
	 */
	public function __construct(
		string $plugin_basename,
		string $plugin_key,
		string $product_id,
		string $update_slug,
		string $license_option_name,
		string|callable $settings_url = '',
		string $settings_page_slug = '',
		?Strings $strings = null,
		string $product_name = '',
		string $license_email_constant = '',
		string $license_key_constant = '',
		string $cron_hook = '',
		bool $manages_cron = true,
		string $update_url = '',
		array $license_servers = [],
		int $request_timeout = 15,
		array $legacy_option_names = [],
		string|callable|null $update_client = null,
		string|callable|null $license_client = null
	) {
		if (
			$plugin_basename === ''
			|| $plugin_key === ''
			|| $product_id === ''
			|| $update_slug === ''
			|| $license_option_name === ''
		) {
			throw new \InvalidArgumentException(
				'Missing required data to initialize the plugin updater: plugin_basename, plugin_key, product_id, update_slug and license_option_name are all required.'
			);
		}
		
		$this->cron_hook = $cron_hook !== '' ? $cron_hook : self::OPTION_PREFIX . $plugin_key . '_license_check';
		$this->legacy_option_names = $legacy_option_names;
		$this->license_client_factory = self::to_client_factory( $license_client, License_Client_Interface::class );
		$this->license_email_constant = $license_email_constant !== '' ? $license_email_constant : \strtoupper( $plugin_key ) . '_LICENSE_EMAIL';
		$this->license_key_constant = $license_key_constant !== '' ? $license_key_constant : \strtoupper( $plugin_key ) . '_LICENSE_KEY';
		$this->license_option_name = $license_option_name;
		$this->license_servers = $license_servers !== [] ? $license_servers : self::DEFAULT_LICENSE_SERVERS;
		$this->manages_cron = $manages_cron;
		$this->plugin_basename = $plugin_basename;
		$this->plugin_key = $plugin_key;
		$this->product_id = $product_id;
		$this->product_name = $product_name !== '' ? $product_name : $product_id;
		$this->request_timeout = $request_timeout;
		$this->settings_page_slug = $settings_page_slug;
		$this->settings_url = $settings_url;
		$this->strings = $strings ?? new Strings();
		$this->update_client_factory = self::to_client_factory( $update_client, Update_Client_Interface::class );
		$this->update_slug = $update_slug;
		$this->update_url = $update_url;
	}
	
	/**
	 * Build the license server client.
	 * 
	 * @throws	\InvalidArgumentException If the configured factory returns something unusable.
	 * 
	 * @return	\epiphyt\Plugin_Updater\License_Client_Interface License server client
	 */
	public function create_license_client(): License_Client_Interface {
		if ( $this->license_client_factory === null ) {
			return new License_Client( $this );
		}
		
		$client = ( $this->license_client_factory )( $this );
		
		if ( ! $client instanceof License_Client_Interface ) {
			throw new \InvalidArgumentException(
				'The configured license client does not implement ' . License_Client_Interface::class . '.'
			);
		}
		
		return $client;
	}
	
	/**
	 * Build the update server client.
	 * 
	 * @param	\epiphyt\Plugin_Updater\License	$license License handler
	 * @throws	\InvalidArgumentException If the configured factory returns something unusable.
	 * 
	 * @return	\epiphyt\Plugin_Updater\Update_Client_Interface Update server client
	 */
	public function create_update_client( License $license ): Update_Client_Interface {
		if ( $this->update_client_factory === null ) {
			return new Update_Client( $this, $license );
		}
		
		$client = ( $this->update_client_factory )( $this, $license );
		
		if ( ! $client instanceof Update_Client_Interface ) {
			throw new \InvalidArgumentException(
				'The configured update client does not implement ' . Update_Client_Interface::class . '.'
			);
		}
		
		return $client;
	}
	
	/**
	 * Get the cron hook name.
	 * 
	 * @return	string Cron hook name
	 */
	public function get_cron_hook(): string {
		return $this->cron_hook;
	}
	
	/**
	 * Get the option name storing the last deactivation response.
	 * 
	 * @return	string Option name
	 */
	public function get_deactivation_response_option_name(): string {
		return self::OPTION_PREFIX . $this->plugin_key . '_deactivation_response';
	}
	
	/**
	 * Get the canonical site identity used to identify this installation.
	 * 
	 * On a multisite, this is always the network URL: a network-wide license has
	 * exactly one identity, so a single site must never present itself as a
	 * separate one. The trailing slash is mandatory.
	 * 
	 * @return	string Site identity with a trailing slash
	 */
	public function get_instance_id(): string {
		return \trailingslashit( \is_multisite() ? \network_site_url() : \home_url() );
	}
	
	/**
	 * Get the installed version of the plugin.
	 * 
	 * Read from the plugin header rather than from a client, so the version an
	 * update is compared against never depends on which update server is in use.
	 * 
	 * @return	string Installed version, or an empty string if the plugin is missing
	 */
	public function get_installed_version(): string {
		// cron and CLI requests don't load this function by default
		if ( ! \function_exists( 'get_plugin_data' ) ) {
			require_once \ABSPATH . 'wp-admin/includes/plugin.php';
		}
		
		$plugin_file = \WP_PLUGIN_DIR . '/' . $this->plugin_basename;
		
		if ( ! \file_exists( $plugin_file ) ) {
			return '';
		}
		
		$plugin_data = \get_plugin_data( $plugin_file, false, false );
		
		return \is_string( $plugin_data['Version'] ?? null ) ? $plugin_data['Version'] : '';
	}
	
	/**
	 * Get the legacy option names of a logical key.
	 * 
	 * These are read as a fallback whenever the current option is absent, so
	 * correctness never depends on the migration having run already.
	 * 
	 * @param	string	$key Logical key, one of the Storage KEY_* constants
	 * @return	string[] Legacy option names, most recent first
	 */
	public function get_legacy_option_names( string $key ): array {
		return $this->legacy_option_names[ $key ] ?? [];
	}
	
	/**
	 * Get all legacy option names by logical key.
	 * 
	 * @return	array<string, string[]> Legacy option names by logical key
	 */
	public function get_all_legacy_option_names(): array {
		return $this->legacy_option_names;
	}
	
	/**
	 * Get the name of the constant holding the license email address.
	 * 
	 * @return	string Constant name
	 */
	public function get_license_email_constant(): string {
		return $this->license_email_constant;
	}
	
	/**
	 * Get the name of the constant holding the license key.
	 * 
	 * @return	string Constant name
	 */
	public function get_license_key_constant(): string {
		return $this->license_key_constant;
	}
	
	/**
	 * Get the option name holding the license credentials.
	 * 
	 * This option is owned by the host plugin, never renamed and never written
	 * to by this package.
	 * 
	 * @return	string Option name
	 */
	public function get_license_option_name(): string {
		return $this->license_option_name;
	}
	
	/**
	 * Get the option name storing the last license response.
	 * 
	 * @return	string Option name
	 */
	public function get_license_response_option_name(): string {
		return self::OPTION_PREFIX . $this->plugin_key . '_license_response';
	}
	
	/**
	 * Get the license server URLs.
	 * 
	 * @return	string[] License server URLs
	 */
	public function get_license_servers(): array {
		return $this->license_servers;
	}
	
	/**
	 * Get the platform this installation runs on.
	 * 
	 * Identical to the instance identity by definition.
	 * 
	 * @return	string Platform with a trailing slash
	 */
	public function get_platform(): string {
		return $this->get_instance_id();
	}
	
	/**
	 * Get the plugin basename.
	 * 
	 * @return	string Plugin basename
	 */
	public function get_plugin_basename(): string {
		return $this->plugin_basename;
	}
	
	/**
	 * Get the option name key of this plugin.
	 * 
	 * @return	string Plugin key
	 */
	public function get_plugin_key(): string {
		return $this->plugin_key;
	}
	
	/**
	 * Get the plugin directory slug.
	 * 
	 * This is what WordPress uses for the plugin information screen, not the
	 * plugin basename and not the update server slug.
	 * 
	 * @return	string Plugin slug
	 */
	public function get_plugin_slug(): string {
		$directory = \dirname( $this->plugin_basename );
		
		return $directory !== '.' ? $directory : \basename( $this->plugin_basename, '.php' );
	}
	
	/**
	 * Get the product ID as known by the license server.
	 * 
	 * @return	string Product ID
	 */
	public function get_product_id(): string {
		return $this->product_id;
	}
	
	/**
	 * Get the display name of the product.
	 * 
	 * @return	string Product name
	 */
	public function get_product_name(): string {
		return $this->product_name;
	}
	
	/**
	 * Get the transient name throttling update requests.
	 * 
	 * @return	string Transient name
	 */
	public function get_request_time_transient_name(): string {
		return self::OPTION_PREFIX . $this->plugin_key . '_request_time';
	}
	
	/**
	 * Get the request timeout.
	 * 
	 * @return	int Timeout in seconds
	 */
	public function get_request_timeout(): int {
		return $this->request_timeout;
	}
	
	/**
	 * Get the option name storing the migration schema version.
	 * 
	 * @return	string Option name
	 */
	public function get_schema_option_name(): string {
		return self::OPTION_PREFIX . $this->plugin_key . '_schema';
	}
	
	/**
	 * Get the value of the 'page' query argument of the settings page.
	 * 
	 * @return	string Settings page slug
	 */
	public function get_settings_page_slug(): string {
		return $this->settings_page_slug;
	}
	
	/**
	 * Get the license settings URL.
	 * 
	 * Resolved at call time, since it may depend on the current admin context.
	 * 
	 * @return	string Settings URL
	 */
	public function get_settings_url(): string {
		if ( \is_callable( $this->settings_url ) ) {
			return ( $this->settings_url )();
		}
		
		return $this->settings_url;
	}
	
	/**
	 * Get a single translated string.
	 * 
	 * @param	string	$key String key, one of the Strings class constants
	 * @return	string Translated string
	 */
	public function get_string( string $key ): string {
		$string = $this->strings->get( $key );
		
		/**
		 * Filter a single string of the plugin updater.
		 * 
		 * @param	string	$string The string
		 * @param	string	$key The string key
		 * @param	string	$plugin_key The plugin key
		 */
		$string = (string) \apply_filters( 'epiphyt_plugin_updater_string', $string, $key, $this->plugin_key );
		
		/**
		 * Filter a single string of the plugin updater for a specific plugin.
		 * 
		 * @param	string	$string The string
		 * @param	string	$key The string key
		 */
		return (string) \apply_filters( 'epiphyt_plugin_updater_string_' . $this->plugin_key, $string, $key );
	}
	
	/**
	 * Get the strings object.
	 * 
	 * @return	\epiphyt\Plugin_Updater\Strings Strings object
	 */
	public function get_strings(): Strings {
		return $this->strings;
	}
	
	/**
	 * Get the option name storing update data.
	 * 
	 * @return	string Option name
	 */
	public function get_update_option_name(): string {
		return self::OPTION_PREFIX . $this->plugin_key . '_update';
	}
	
	/**
	 * Get the package slug on the update server.
	 * 
	 * In debug mode, the development package is requested instead.
	 * 
	 * @return	string Update slug
	 */
	public function get_update_slug(): string {
		return $this->update_slug . ( $this->is_debug() ? '-dev' : '' );
	}
	
	/**
	 * Get the transient name caching update data.
	 * 
	 * @return	string Transient name
	 */
	public function get_update_transient_name(): string {
		return self::OPTION_PREFIX . $this->plugin_key . '_update_check';
	}
	
	/**
	 * Get the update server URL.
	 * 
	 * @return	string Update server URL
	 */
	public function get_update_url(): string {
		if ( $this->update_url !== '' ) {
			return $this->update_url;
		}
		
		return $this->is_debug() ? self::DEBUG_UPDATE_URL : self::DEFAULT_UPDATE_URL;
	}
	
	/**
	 * Get the user agent used for remote requests.
	 * 
	 * @return	string User agent
	 */
	public function get_user_agent(): string {
		return \sprintf(
			'epiphyt/wp-plugin-updater; %1$s; %2$s',
			$this->product_id,
			$this->get_instance_id()
		);
	}
	
	/**
	 * Check whether debug mode is enabled.
	 * 
	 * @return	bool Whether debug mode is enabled
	 */
	public function is_debug(): bool {
		return \defined( 'EPIPHYT_DEBUG' ) && \constant( 'EPIPHYT_DEBUG' );
	}
	
	/**
	 * Check whether this package schedules its cron event itself.
	 * 
	 * @return	bool Whether the cron event is managed here
	 */
	public function manages_cron(): bool {
		return $this->manages_cron;
	}
	
	/**
	 * Normalize a configured client into a factory.
	 * 
	 * A class name is validated here rather than on first use, so a typo surfaces
	 * during bootstrap instead of silently breaking updates weeks later. Callables
	 * can only be validated once they have produced an instance, which happens in
	 * the create_*_client() methods.
	 * 
	 * @param	class-string|callable|null	$client Client class name, factory, or null for the default
	 * @param	class-string				$interface_name Interface the client has to implement
	 * @throws	\InvalidArgumentException If the class does not exist or does not implement the interface.
	 * 
	 * @return	callable|null Factory, or null to use the default client
	 */
	private static function to_client_factory( string|callable|null $client, string $interface_name ): ?callable {
		if ( $client === null || $client === '' ) {
			return null;
		}
		
		if ( \is_string( $client ) ) {
			if ( ! \class_exists( $client ) ) {
				throw new \InvalidArgumentException(
					\sprintf( 'The configured client class "%s" does not exist.', \esc_html( $client ) )
				);
			}
			
			if ( ! \is_subclass_of( $client, $interface_name ) ) {
				throw new \InvalidArgumentException(
					\sprintf(
						'The configured client class "%1$s" does not implement %2$s.',
						\esc_html( $client ),
						\esc_html( $interface_name )
					)
				);
			}
			
			return static function ( mixed ...$arguments ) use ( $client ): object {
				return new $client( ...$arguments );
			};
		}
		
		return $client;
	}
}
