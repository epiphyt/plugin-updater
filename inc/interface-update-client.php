<?php
declare(strict_types = 1);

namespace epiphyt\Plugin_Updater;

/**
 * Contract of an update server client.
 * 
 * Implement this to talk to an update server that is not
 * [wp-update-server](https://github.com/YahnisElsts/wp-update-server), and pass
 * the implementation to Config via its ‘update_client’ argument.
 * 
 * Everything around the raw metadata – caching, throttling, comparing versions,
 * building the objects WordPress expects – is handled by this package, so an
 * implementation only has to produce the metadata array.
 * 
 * @author	Epiphyt
 * @license	GPL2
 * @package	epiphyt\Plugin_Updater
 */
interface Update_Client_Interface {
	/**
	 * Get the raw metadata of the plugin from the update server.
	 * 
	 * All keys are optional, and anything not listed here is ignored:
	 * 
	 * - ‘version’ string The offered version, compared against the installed one
	 * - ‘download_url’ string URL of the ZIP file
	 * - ‘name’ string Display name, defaults to the configured product name
	 * - ‘homepage’ string Product URL
	 * - ‘author’ string Author name
	 * - ‘author_homepage’ string Author URL
	 * - ‘requires’ string Minimum WordPress version
	 * - ‘tested’ string Highest tested WordPress version
	 * - ‘sections’ array<string, string> Sections of the plugin details screen
	 * - ‘banners’ array{high?: string, low?: string} Banner URLs
	 * - ‘icons’ array<string, string> Icon URLs
	 * 
	 * Returning a \WP_Error leaves the update data untouched: WordPress keeps
	 * whatever it already knows rather than reporting that no update exists.
	 * A failing update server must therefore never be reported as a success.
	 * 
	 * @return	array<string, mixed>|\WP_Error Metadata, or an error
	 */
	public function get_metadata(): array|\WP_Error;
}
