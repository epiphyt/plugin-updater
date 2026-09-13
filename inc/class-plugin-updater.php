<?php // phpcs:disable Universal.Classes.RequireFinalClass.NonFinalClassFound
declare(strict_types = 1);

namespace epiphyt\Plugin_Updater;

/**
 * Per-plugin updater facade.
 * 
 * Deliberately not final: this is the single seam host plugins mock in their
 * own test suites.
 * 
 * @author	Epiphyt
 * @license	GPL2
 * @package	epiphyt\Plugin_Updater
 */
class Plugin_Updater {
	/**
	 * @var		\epiphyt\Plugin_Updater\Config Plugin configuration
	 */
	private Config $config;
	
	/**
	 * @var		\epiphyt\Plugin_Updater\Cron Cron handler
	 */
	private Cron $cron;
	
	/**
	 * @var		bool Whether hooks have been registered
	 */
	private bool $is_initialized = false;
	
	/**
	 * @var		\epiphyt\Plugin_Updater\License License handler
	 */
	private License $license;
	
	/**
	 * @var		\epiphyt\Plugin_Updater\Migration Migration handler
	 */
	private Migration $migration;
	
	/**
	 * @var		\epiphyt\Plugin_Updater\Notices Notice handler
	 */
	private Notices $notices;
	
	/**
	 * @var		\epiphyt\Plugin_Updater\Package_Mover Package mover
	 */
	private Package_Mover $package_mover;
	
	/**
	 * @var		\epiphyt\Plugin_Updater\Update_Checker Update checker
	 */
	private Update_Checker $update_checker;
	
	/**
	 * @var		\epiphyt\Plugin_Updater\Update_Client_Interface Update server client
	 */
	private Update_Client_Interface $update_client;
	
	/**
	 * Class constructor.
	 * 
	 * @param	\epiphyt\Plugin_Updater\Config	$config Plugin configuration
	 */
	public function __construct( Config $config ) {
		$storage = new Storage( $config );
		$this->config = $config;
		$this->cron = new Cron( $config );
		$this->license = new License( $config, $storage, $config->create_license_client() );
		$this->migration = new Migration( $config );
		$this->notices = new Notices( $config, $this->license );
		$this->update_client = $config->create_update_client( $this->license );
		$this->update_checker = new Update_Checker(
			$config,
			$storage,
			$this->update_client,
			new Update_Response( $config, $this->license )
		);
		$this->package_mover = new Package_Mover( $config, $this->update_checker );
	}
	
	/**
	 * Register all hooks.
	 * 
	 * Must not run before the ‘init’ action: the translated strings this package
	 * renders are passed in by the host plugin, and translation data is not
	 * available any earlier. Every hook registered here fires after ‘init’, so
	 * nothing is missed by waiting.
	 */
	public function init(): void {
		if ( $this->is_initialized ) {
			return;
		}
		
		if ( \did_action( 'init' ) === 0 ) {
			\_doing_it_wrong(
				__METHOD__,
				'The plugin updater must not be initialized before the "init" action, because translated strings need loaded translation data. Hook registration has been deferred to "init".',
				'1.0.0'
			);
			\add_action( 'init', [ $this, 'init' ], 20 );
			
			return;
		}
		
		$this->is_initialized = true;
		
		$this->cron->register_hooks();
		$this->license->register_hooks();
		$this->migration->register_hooks();
		$this->notices->register_hooks();
		$this->package_mover->register_hooks();
		$this->update_checker->register_hooks();
	}
	
	/**
	 * Activate the license.
	 * 
	 * @param	\epiphyt\Plugin_Updater\Credentials|null	$credentials Credentials, or null to use the stored ones
	 * @return	true|\WP_Error True on success, an error otherwise
	 */
	public function activate_license( ?Credentials $credentials = null ): bool|\WP_Error {
		return $this->license->activate( $credentials );
	}
	
	/**
	 * Check whether the license can be deactivated from here.
	 * 
	 * @return	bool Whether the license can be deactivated
	 */
	public function can_deactivate_license(): bool {
		return $this->license->can_deactivate();
	}
	
	/**
	 * Check the license against the license server.
	 * 
	 * @param	bool	$force Whether to bypass all throttling
	 */
	public function check_license( bool $force = false ): void {
		$this->license->check( $force );
	}
	
	/**
	 * Deactivate the license.
	 * 
	 * @param	\epiphyt\Plugin_Updater\Credentials|null	$credentials Credentials, or null to use the stored ones
	 * @return	true|\WP_Error True on success, an error otherwise
	 */
	public function deactivate_license( ?Credentials $credentials = null ): bool|\WP_Error {
		return $this->license->deactivate( $credentials );
	}
	
	/**
	 * Get the plugin configuration.
	 * 
	 * @return	\epiphyt\Plugin_Updater\Config Plugin configuration
	 */
	public function get_config(): Config {
		return $this->config;
	}
	
	/**
	 * Get the notice shown when the license has expired.
	 * 
	 * @return	string Escaped notice markup
	 */
	public function get_expired_notice(): string {
		return $this->notices->get_expired_notice();
	}
	
	/**
	 * Get the license handler.
	 * 
	 * @internal
	 * 
	 * @return	\epiphyt\Plugin_Updater\License License handler
	 */
	public function get_license(): License {
		return $this->license;
	}
	
	/**
	 * Get the raw metadata of this plugin from the update server.
	 * 
	 * @return	array<string, mixed>|\WP_Error Metadata, or an error
	 */
	public function get_metadata(): array|\WP_Error {
		return $this->update_client->get_metadata();
	}
	
	/**
	 * Get the update checker.
	 * 
	 * @internal
	 * 
	 * @return	\epiphyt\Plugin_Updater\Update_Checker Update checker
	 */
	public function get_update_checker(): Update_Checker {
		return $this->update_checker;
	}
	
	/**
	 * Check whether the license is active for this installation.
	 * 
	 * @return	bool Whether the license is active
	 */
	public function is_license_activated(): bool {
		return $this->license->is_activated();
	}
	
	/**
	 * Check whether an update is outside the licensed version range.
	 * 
	 * @param	string	$new_version Version to check, or an empty string to use the pending update
	 * @return	bool Whether the license has expired for that version
	 */
	public function is_license_expired( string $new_version = '' ): bool {
		return $this->license->is_expired( $new_version );
	}
	
	/**
	 * Output the notice shown when the license has expired.
	 */
	public function render_expired_notice(): void {
		echo \wp_kses_post( $this->notices->get_expired_notice() );
	}
	
	/**
	 * Schedule the recurring license check.
	 */
	public function schedule_cron(): void {
		$this->cron->schedule();
	}
	
	/**
	 * Remove the recurring license check.
	 */
	public function unschedule_cron(): void {
		$this->cron->unschedule();
	}
}
