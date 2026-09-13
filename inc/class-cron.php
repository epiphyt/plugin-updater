<?php
declare(strict_types = 1);

namespace epiphyt\Plugin_Updater;

/**
 * Scheduler for the recurring license check.
 * 
 * @author	Epiphyt
 * @license	GPL2
 * @package	epiphyt\Plugin_Updater
 */
final class Cron {
	private const RECURRENCE = 'twicedaily';
	
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
	 * Check whether the event is scheduled.
	 * 
	 * @return	bool Whether the event is scheduled
	 */
	public function is_scheduled(): bool {
		return \wp_next_scheduled( $this->config->get_cron_hook() ) !== false;
	}
	
	/**
	 * Register all hooks.
	 */
	public function register_hooks(): void {
		if ( ! $this->config->manages_cron() ) {
			return;
		}
		
		$this->schedule();
	}
	
	/**
	 * Schedule the recurring license check.
	 */
	public function schedule(): void {
		if ( $this->is_scheduled() ) {
			return;
		}
		
		\wp_schedule_event( \time(), self::RECURRENCE, $this->config->get_cron_hook() );
	}
	
	/**
	 * Remove the recurring license check.
	 */
	public function unschedule(): void {
		\wp_clear_scheduled_hook( $this->config->get_cron_hook() );
	}
}
