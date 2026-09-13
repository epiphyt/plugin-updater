# epiphyt/wp-plugin-updater

Update and license handling for commercial WordPress plugins distributed through
[wp-update-server](https://github.com/YahnisElsts/wp-update-server) and
[WooCommerce Software Add-on](https://woocommerce.com/products/software-add-on/).

## Requirements

- PHP 8.1 or higher
- WordPress 6.5 or higher

## Installation

```bash
composer require epiphyt/wp-plugin-updater
```

The plugin has to load Composer's autoloader, which the Epiphyt plugins do not do
by default – they register their own `spl_autoload_register`. Add this to the main
plugin file before your own autoloader:

```php
if ( \file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}
```

## Usage

Register the updater on `init` – never earlier. The package carries no text
domain of its own: every user-facing string is translated by *your* plugin and
passed in, and translation data is not available before `init`.

```php
use epiphyt\Plugin_Updater\Config;
use epiphyt\Plugin_Updater\Registry;
use epiphyt\Plugin_Updater\Strings;

\add_action( 'init', static function (): void {
	$config = new Config(
		plugin_basename: \MY_PLUGIN_BASE,
		plugin_key: 'my_plugin',
		product_id: 'My Plugin',
		update_slug: 'my-plugin',
		license_option_name: 'my_plugin_license_options',
		settings_url: \admin_url( 'options-general.php?page=my-plugin&tab=license' ),
		settings_page_slug: 'my-plugin',
		strings: new Strings( [
			Strings::ACTIVATION_FAILED_NOTICE => \esc_html__( 'License activation of %s failed:', 'my-plugin' ),
			Strings::UNKNOWN_ERROR => \esc_html__( 'Unknown error.', 'my-plugin' ),
			// …
		] )
	);

	Registry::register( $config )->init();
}, 20 );
```

Anywhere else, get the instance back from the registry:

```php
$updater = Registry::get( \MY_PLUGIN_BASE );

if ( $updater !== null && $updater->is_license_activated() ) {
	// …
}
```

### Configuration

Only the first five arguments are required.

| Argument | Description |
| --- | --- |
| `plugin_basename` | `my-plugin/my-plugin.php`. Also the registry key. |
| `plugin_key` | Prefix for this package's own options, e.g. `my_plugin`. |
| `product_id` | Product ID on the license server, e.g. `My Plugin`. |
| `update_slug` | Package slug on the **update server**. Not necessarily the plugin slug. |
| `license_option_name` | Option holding the license credentials. Owned by your plugin; this package only reads it. |
| `settings_url` | `string` or `callable(): string`. A callable is resolved at render time, so it may depend on the admin context. |
| `settings_page_slug` | The `page` query argument of your license screen, used to decide where notices appear. |
| `strings` | A `Strings` object with your translated strings. |
| `product_name` | Display name. Defaults to `product_id`. |
| `license_email_constant` / `license_key_constant` | wp-config constants overriding the stored credentials. Default to `MY_PLUGIN_LICENSE_EMAIL` / `_KEY`. |
| `cron_hook` | Hook for the recurring license check. Defaults to `epiphyt_updater_{plugin_key}_license_check`. |
| `manages_cron` | Whether this package schedules that event itself. Set to `false` if your plugin already owns a suitable cron hook. |
| `update_url` | Update server. Defaults to `https://update.epiph.yt`, or the dev server when `EPIPHYT_DEBUG` is set. |
| `license_servers` | License server URLs. The first usable response wins. |
| `request_timeout` | Seconds. Defaults to `15`. |
| `legacy_option_names` | Old option names to read from and migrate, keyed by `Storage::KEY_*`. |
| `update_client` | Class name or factory replacing the update server client. See [Custom servers](#custom-servers). |
| `license_client` | Class name or factory replacing the license server client. See [Custom servers](#custom-servers). |

### Translated strings

`Strings` holds twelve keys, each with an untranslated English fallback. Passing
an unknown key triggers `_doing_it_wrong()` rather than failing silently.

`ACTIVATION_FAILED_NOTICE`, `ACTIVATION_FAILED_ROW`, `ACTIVATION_FAILED_ROW_HINT`,
`DEACTIVATION_FAILED_NOTICE`, `EDIT_CREDENTIALS`, `EXPIRED_NOTICE`,
`INVALID_REQUEST`, `MISSING_CREDENTIALS`, `RENEWAL_LINK_TEXT`, `RENEWAL_URL`,
`UNKNOWN_ERROR`, `UPGRADE_NOTICE`.

Because the strings live in *your* plugin's source, `wp i18n make-pot` picks them
up where they are. Do **not** add `vendor/` to the scan path – this package
contains no translatable strings, and scanning it would emit phantom entries.

Site-level overrides are possible through the `epiphyt_plugin_updater_string`
filter and its per-plugin variant `epiphyt_plugin_updater_string_{plugin_key}`.

## Custom servers

`update_url` and `license_servers` point the default clients at a different host,
which is enough as long as that host speaks the same protocol –
[wp-update-server](https://github.com/YahnisElsts/wp-update-server) for updates and
the [WooCommerce Software Add-on](https://woocommerce.com/products/software-add-on/)
for licenses.

For a server that speaks something else, replace the client itself. Both sides are
defined by an interface, and everything around them – caching, throttling, version
comparison, the license state machine, notices, the WordPress hooks – keeps working
unchanged.

| Interface | Replaces | Config argument |
| --- | --- | --- |
| `Update_Client_Interface` | `Update_Client` | `update_client` |
| `License_Client_Interface` | `License_Client` | `license_client` |

### Replacing the update server

An implementation only has to return the metadata; this package turns it into
whatever WordPress wants, caches it and decides whether it constitutes an update.

```php
namespace My_Plugin;

use epiphyt\Plugin_Updater\Config;
use epiphyt\Plugin_Updater\License;
use epiphyt\Plugin_Updater\Update_Client_Interface;

final class Update_Client implements Update_Client_Interface {
	/**
	 * @var	\epiphyt\Plugin_Updater\Config Plugin configuration
	 */
	private Config $config;
	
	/**
	 * @var	\epiphyt\Plugin_Updater\License License handler
	 */
	private License $license;
	
	public function __construct( Config $config, License $license ) {
		$this->config = $config;
		$this->license = $license;
	}
	
	public function get_metadata(): array|\WP_Error {
		$response = \wp_remote_get( 'https://updates.example.com/api/v2/' . $this->config->get_update_slug(), [
			'timeout' => $this->config->get_request_timeout(),
			'user-agent' => $this->config->get_user_agent(),
		] );
		
		if ( \is_wp_error( $response ) ) {
			return $response;
		}
		
		$data = \json_decode( \wp_remote_retrieve_body( $response ), true );
		
		if ( ! \is_array( $data ) ) {
			return new \WP_Error( 'my_plugin_invalid_response', 'The update server returned no valid JSON.' );
		}
		
		return [
			'version' => $data['latest'],
			'download_url' => $data['zip'],
			'requires' => $data['min_wp'],
			'tested' => $data['max_wp'],
			'sections' => [ 'changelog' => $data['changelog_html'] ],
		];
	}
}
```

Every key is optional; see the docblock of `Update_Client_Interface` for the full
list. Two rules matter:

- **Return a `\WP_Error` on failure, never an empty array.** An error leaves the
  update data untouched, so WordPress keeps what it already knows. An empty array
  is a valid answer meaning "no version information", and a flaky server would
  then withdraw an update that is genuinely available.
- **`download_url` has to be reachable by the site.** WordPress downloads it
  itself, so the URL has to carry whatever authenticates the download. Unless the
  URL already contains both `email` and `license_key`, this package appends
  `email`, `license_key`, `platform` and `product_id` to it – which is how the
  default server authenticates downloads. Return a URL that already carries those
  two parameters to suppress that, for instance a signed one-time URL, and the
  credentials are then not sent to the download host at all.

### Replacing the license server

```php
namespace My_Plugin;

use epiphyt\Plugin_Updater\Config;
use epiphyt\Plugin_Updater\Credentials;
use epiphyt\Plugin_Updater\License_Client_Interface;

final class License_Client implements License_Client_Interface {
	/**
	 * @var	\epiphyt\Plugin_Updater\Config Plugin configuration
	 */
	private Config $config;
	
	public function __construct( Config $config ) {
		$this->config = $config;
	}
	
	public function activate( Credentials $credentials ): array|\WP_Error {
		return $this->request( 'activate', $credentials );
	}
	
	public function check( Credentials $credentials ): array|\WP_Error {
		return $this->request( 'status', $credentials );
	}
	
	public function deactivate( Credentials $credentials ): array|\WP_Error {
		return $this->request( 'deactivate', $credentials );
	}
	
	private function request( string $endpoint, Credentials $credentials ): array|\WP_Error {
		$response = \wp_remote_post( 'https://licenses.example.com/' . $endpoint, [
			'body' => \array_merge( $credentials->to_request_args(), [
				'instance' => $this->config->get_instance_id(),
				'product_id' => $this->config->get_product_id(),
			] ),
			'timeout' => $this->config->get_request_timeout(),
			'user-agent' => $this->config->get_user_agent(),
		] );
		
		if ( \is_wp_error( $response ) ) {
			return $response;
		}
		
		$data = \json_decode( \wp_remote_retrieve_body( $response ), true );
		
		if ( ! \is_array( $data ) ) {
			return new \WP_Error( 'my_plugin_invalid_response', 'The license server returned no valid JSON.' );
		}
		
		// translate the response into the shape the state machine reads
		return [
			'success' => (bool) $data['ok'],
			'error' => $data['message'] ?? '',
			'activations' => \array_map(
				static fn ( array $site ): array => [
					'instance' => $site['url'],
					'software_version' => $site['covered_until_version'],
				],
				$data['sites'] ?? []
			),
		];
	}
}
```

The response shape is the contract, and three things are load-bearing:

- **`activations` decides whether the site is licensed.** An installation counts
  as activated when one entry's `instance` matches `Config::get_instance_id()`,
  compared with a trailing slash on both sides. Returning `success` without a
  matching activation makes this package activate the license, so a server that
  omits `activations` triggers an activation on every check and burns through the
  activation limit.
- **The highest `software_version` across all activations is the highest version
  the license covers**, which drives the expiry notices and `is_license_expired()`.
  Omit it if the license has no version ceiling – nothing is then reported expired.
- **`deactivate()` must release only this installation.** The WooCommerce API
  deactivates *every* activation when no instance is sent, which is exactly how
  customers lost their whole license in the previous implementation.

`check()` runs on a schedule and whenever the credentials are saved, so it must
not change anything on the server.

### Registering the implementation

Pass a class name, and it is instantiated with the constructor arguments shown
above:

```php
$config = new Config(
	// …
	update_client: \My_Plugin\Update_Client::class,
	license_client: \My_Plugin\License_Client::class,
);
```

The class name is validated while `Config` is constructed – a typo or a class
missing the interface throws an `\InvalidArgumentException` during bootstrap
rather than silently breaking updates later.

If the client needs anything beyond `Config` and `License`, pass a factory
instead:

```php
license_client: static fn ( Config $config ): License_Client_Interface
	=> new \My_Plugin\License_Client( $config, $my_http_client ),
```

Custom clients are not given the credentials to store or the responses to
interpret: `Credentials` arrives as an argument, the return value is stored and
read by this package, and the options, transients and multisite scoping stay where
they are. Replacing a client changes *where* the data comes from, never what is
done with it.

## Site identity

`instance` and `platform` identify the installation on the license server, and
activations are keyed by them. This package computes exactly one value:

```php
\trailingslashit( \is_multisite() ? \network_site_url() : \home_url() );
```

A trailing slash is mandatory, and on a multisite the network URL is always used –
a network-wide license has one identity, so a single site must never present
itself as a separate one. There is deliberately no configuration for this: the
three divergent computations in the previous libraries are what caused sites to
consume more activations than they should.

Activations recorded before this package normalised the identity are matched
leniently, comparing both sides with a trailing slash, so an existing activation
cannot trigger a spurious re-activation.

## Options

All options this package owns are prefixed per plugin:

- `epiphyt_updater_{plugin_key}_license_response`
- `epiphyt_updater_{plugin_key}_deactivation_response`
- `epiphyt_updater_{plugin_key}_schema` (and `_schema_migration`)
- transients `epiphyt_updater_{plugin_key}_update_check` and `_request_time`

The option holding the license credentials is **never** renamed or written to –
it belongs to the host plugin.

### Migrating from the old libraries

Pass the old names and they are read as a fallback and copied across once:

```php
legacy_option_names: [
	Storage::KEY_LICENSE_RESPONSE => [ 'epiphyt_license_response' ],
	Storage::KEY_DEACTIVATION_RESPONSE => [ 'epiphyt_license_deactivation_response' ],
],
```

The migration copies, never moves, so a downgrade still finds its data. It runs
on `admin_init`, during cron and under WP-CLI, never on a front-end request, and
it never overwrites an existing value. Define `EPIPHYT_UPDATER_SKIP_MIGRATION` to
disable it; correctness does not depend on it having run, because the legacy names
are read as a fallback anyway.

## Development

```bash
composer install
composer test      # phpcs + phpstan + phpunit
```

`composer.json` uses a classmap autoloader, so run `composer dump-autoload` after
adding a class.

### End-to-end tests

The end-to-end suite builds a throwaway plugin around this package, serves a fake
update and license server, boots real WordPress via
[WordPress Playground](https://wordpress.github.io/wordpress-playground/) and
performs an actual plugin update – on a single site and on a multisite.

```bash
composer test:e2e            # both
composer test:e2e:single
composer test:e2e:multisite
```

Failure paths are covered too:

```bash
php tests/e2e/run.php --matrix=single --scenario=invalid-key
php tests/e2e/run.php --matrix=single --scenario=expired
php tests/e2e/run.php --matrix=single --scenario=activation-limit-reached
php tests/e2e/run.php --matrix=single --scenario=server-500
php tests/e2e/run.php --matrix=single --scenario=malformed-json
```

Requires Node 20 and the `zip` PHP extension. Two constraints are worth knowing
if you extend the suite: WordPress multisite refuses a custom port, so the site
URL has to be port-less; and the plugin is installed from a ZIP rather than
mounted, because WordPress has to delete its directory during an update and a
mount point cannot be removed.

## License

GPL-2.0-only. See [LICENSE](LICENSE).
