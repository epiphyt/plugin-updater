<?php
declare(strict_types = 1);

namespace epiphyt\Plugin_Updater;

/**
 * Contract of a license server client.
 * 
 * Implement this to talk to a license server that is not the
 * [WooCommerce Software Add-on](https://woocommerce.com/products/software-add-on/),
 * and pass the implementation to Config via its ‘license_client’ argument.
 * 
 * Responses are plain arrays in the shape the WooCommerce Software Add-on
 * returns, since that is what the license state machine of this package reads:
 * 
 * - ‘success’ bool Whether the request succeeded
 * - ‘error’ string Error message, only on failure
 * - ‘activations’ array<int, array{instance?: string, software_version?: string}>
 *   All installations this license is currently activated for
 * 
 * An installation counts as activated when ‘activations’ contains an entry whose
 * ‘instance’ matches Config::get_instance_id(), compared with a trailing slash on
 * both sides. The highest ‘software_version’ of all activations is the highest
 * version the license covers, which is what drives the expiry notices.
 * 
 * @author	Epiphyt
 * @license	GPL2
 * @package	epiphyt\Plugin_Updater
 */
interface License_Client_Interface {
	/**
	 * Activate a license for this installation.
	 * 
	 * @param	\epiphyt\Plugin_Updater\Credentials	$credentials License credentials
	 * @return	array<string, mixed>|\WP_Error Response, or an error
	 */
	public function activate( Credentials $credentials ): array|\WP_Error;
	
	/**
	 * Get the current state of a license.
	 * 
	 * Must not change anything on the license server: this runs on a schedule and
	 * whenever the credentials are saved.
	 * 
	 * @param	\epiphyt\Plugin_Updater\Credentials	$credentials License credentials
	 * @return	array<string, mixed>|\WP_Error Response, or an error
	 */
	public function check( Credentials $credentials ): array|\WP_Error;
	
	/**
	 * Deactivate the license for this installation.
	 * 
	 * Must only ever release the activation of this installation, identified by
	 * Config::get_instance_id(), never every activation of the license.
	 * 
	 * @param	\epiphyt\Plugin_Updater\Credentials	$credentials License credentials
	 * @return	array<string, mixed>|\WP_Error Response, or an error
	 */
	public function deactivate( Credentials $credentials ): array|\WP_Error;
}
