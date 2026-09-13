<?php
/**
 * Runs inside WordPress via `wp eval-file` and performs a real plugin update.
 *
 * Results are written to a mounted directory so the host-side runner can assert
 * on them; relying on captured stdout proved unreliable.
 */
declare(strict_types=1);

$result = [
    'errors' => [],
    'steps' => [],
];

/**
 * Record a named value.
 */
$record = static function (string $key, $value) use (&$result): void {
    $result['steps'][$key] = $value;
};

/**
 * Record an error.
 */
$fail = static function (string $message) use (&$result): void {
    $result['errors'][] = $message;
};

if (!function_exists('get_plugin_data')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

$basename = 'fake-plugin/fake-plugin.php';
$pluginFile = WP_PLUGIN_DIR . '/' . $basename;

$record('is_multisite', is_multisite());
$record('version_before', get_plugin_data($pluginFile, false, false)['Version']);

// store credentials the way the host plugin's settings form would
$option = [
    'license_email' => 'licensee@example.com',
    'license_key' => 'VALID-LICENSE-KEY',
];

if (is_multisite()) {
    update_site_option('fake_plugin_license_options', $option);
} else {
    update_option('fake_plugin_license_options', $option);
}

$updater = \epiphyt\Plugin_Updater\Registry::get($basename);

if ($updater === null) {
    $fail('The updater was not registered. Is the plugin active and did "init" run?');

    file_put_contents('/results/result.json', json_encode($result, JSON_PRETTY_PRINT));

    return;
}

// the canonical identity, asserted on the wire by the runner as well
$record('instance_id', $updater->get_config()->get_instance_id());
$record('platform', $updater->get_config()->get_platform());

$updater->check_license(true);

$record('license_activated', $updater->is_license_activated());
$record('license_response', $updater->get_license()->get_response());

// prove the injected strings are in use rather than the package defaults
$record('injected_unknown_error', $updater->get_config()->get_string(
    \epiphyt\Plugin_Updater\Strings::UNKNOWN_ERROR
));

// force a fresh update check
delete_site_transient('update_plugins');
wp_update_plugins();

$updates = get_site_transient('update_plugins');
$offered = $updates->response[$basename] ?? null;

$record('update_offered', $offered !== null);

if ($offered !== null) {
    $record('offered_version', $offered->new_version);
    $record('offered_slug', $offered->slug);
    $record('offered_plugin', $offered->plugin);
    $record('offered_package', $offered->package);
    $record('upgrade_notice', $offered->upgrade_notice ?? null);
} else {
    $record('no_update_entry', isset($updates->no_update[$basename]));
}

if ($offered !== null) {
    if (!WP_Filesystem()) {
        $fail('Could not initialize the filesystem.');
    }

    $skin = new Automatic_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    $outcome = $upgrader->upgrade($basename);

    $record('upgrade_outcome', $outcome === true ? 'success' : var_export($outcome, true));
    $record('upgrade_messages', $skin->get_upgrade_messages());

    if (is_wp_error($outcome)) {
        $fail('Upgrade failed: ' . $outcome->get_error_message());
    }
}

clearstatcache();
wp_clean_plugins_cache();

$record('version_after', file_exists($pluginFile)
    ? get_plugin_data($pluginFile, false, false)['Version']
    : null);
$record('installed_in_correct_directory', is_dir(WP_PLUGIN_DIR . '/fake-plugin'));

file_put_contents('/results/result.json', json_encode($result, JSON_PRETTY_PRINT));
