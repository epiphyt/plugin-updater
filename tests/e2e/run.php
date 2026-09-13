<?php
/**
 * End-to-end runner.
 *
 * Builds a fake plugin around the package, serves a fake update and license
 * server, boots WordPress via Playground, performs a real plugin update and
 * asserts the outcome – on a single site and on a multisite.
 *
 * Usage: php tests/e2e/run.php [--matrix=all|single|multisite] [--scenario=valid]
 */
declare(strict_types=1);

const OFFERED_VERSION = '1.1.0';
const PORT = 9753;

$root = dirname(__DIR__, 2);
$e2e = __DIR__;
$work = $e2e . '/.work';

$options = getopt('', ['matrix::', 'scenario::', 'keep::']);
$matrix = $options['matrix'] ?? 'all';
$scenario = $options['scenario'] ?? 'valid';
$matrices = $matrix === 'all' ? ['single', 'multisite'] : [$matrix];

$failures = [];

/**
 * Print a status line.
 */
function say(string $message): void
{
    echo $message . PHP_EOL;
}

/**
 * Remove a directory recursively.
 */
function rmdir_recursive(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($directory);
}

/**
 * Copy a directory recursively.
 */
function copy_recursive(string $source, string $target): void
{
    @mkdir($target, 0o777, true);

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($items as $item) {
        $destination = $target . '/' . $items->getSubPathName();

        if ($item->isDir()) {
            @mkdir($destination, 0o777, true);

            continue;
        }

        copy($item->getPathname(), $destination);
    }
}

/**
 * Build the plugin directory that gets mounted into WordPress.
 */
function build_plugin(string $root, string $e2e, string $work, string $version, string $serverUrl): string
{
    $target = $work . '/plugin';

    rmdir_recursive($target);
    copy_recursive($e2e . '/fixtures/fake-plugin', $target);
    copy_recursive($root . '/inc', $target . '/vendor/epiphyt/wp-plugin-updater/inc');

    $file = $target . '/fake-plugin.php';
    $contents = (string) file_get_contents($file);
    $contents = str_replace(
        ['__UPDATE_URL__', '__LICENSE_URL__'],
        [$serverUrl . '/', $serverUrl . '/?wc-api=software-api'],
        $contents
    );
    $contents = preg_replace('/^ \* Version: .*$/m', ' * Version: ' . $version, $contents);

    file_put_contents($file, (string) $contents);

    return $target;
}

/**
 * Zip a directory, placing its contents under a single inner folder.
 */
function build_zip(string $work, string $pluginDir, string $inner, string $version, string $zipPath): void
{
    $staging = $work . '/zip-' . $inner;

    rmdir_recursive($staging);
    copy_recursive($pluginDir, $staging . '/' . $inner);

    $file = $staging . '/' . $inner . '/fake-plugin.php';
    $contents = (string) file_get_contents($file);
    $contents = preg_replace('/^ \* Version: .*$/m', ' * Version: ' . $version, $contents);

    file_put_contents($file, (string) $contents);

    @mkdir(dirname($zipPath), 0o777, true);
    @unlink($zipPath);

    $zip = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        throw new RuntimeException('Could not create ' . $zipPath . '.');
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($items as $item) {
        $relative = $items->getSubPathName();

        if ($item->isDir()) {
            $zip->addEmptyDir($relative);

            continue;
        }

        $zip->addFile($item->getPathname(), $relative);
    }

    $zip->close();
    rmdir_recursive($staging);
}

/**
 * Assert a condition and collect the failure.
 *
 * @param	array<int, string>	$failures
 */
function check(array &$failures, string $label, bool $condition, string $detail = ''): void
{
    if ($condition) {
        say('  PASS  ' . $label);

        return;
    }

    $failures[] = $label . ($detail !== '' ? ' – ' . $detail : '');
    say('  FAIL  ' . $label . ($detail !== '' ? ' – ' . $detail : ''));
}

// ------------------------------------------------------------------ execution
@mkdir($work, 0o777, true);

$serverUrl = 'http://127.0.0.1:' . PORT;
$packageDir = $e2e . '/server/packages';
$stateFile = $e2e . '/server/state.json';

foreach ($matrices as $current) {
    say('');
    say('=== ' . strtoupper($current) . ' (scenario: ' . $scenario . ') ===');

    @unlink($stateFile);

    $pluginDir = build_plugin($root, $e2e, $work, '1.0.0', $serverUrl);

    // the plugin is installed from a ZIP rather than mounted: WordPress has to
    // be able to delete its directory during the update, and a mount point
    // cannot be removed
    $initialZip = $work . '/fake-plugin-1.0.0.zip';
    build_zip($work, $pluginDir, 'fake-plugin', '1.0.0', $initialZip);

    // the directory inside the update ZIP deliberately does not match the
    // plugin slug, which is what the real update server produces
    build_zip(
        $work,
        $pluginDir,
        'fake-plugin-' . OFFERED_VERSION . '-' . bin2hex(random_bytes(4)),
        OFFERED_VERSION,
        $packageDir . '/fake-plugin.zip'
    );

    $results = $work . '/results';
    rmdir_recursive($results);
    @mkdir($results, 0o777, true);

    // start the fake server
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . PORT, $e2e . '/server/router.php'],
        $descriptors,
        $pipes,
        $e2e,
        ['EPIPHYT_E2E_SCENARIO' => $scenario] + $_ENV
    );

    if (!is_resource($server)) {
        say('Could not start the fake server.');
        exit(1);
    }

    // wait for the server to accept connections
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        $socket = @fsockopen('127.0.0.1', PORT, $errno, $errstr, 0.2);

        if ($socket !== false) {
            fclose($socket);

            break;
        }

        usleep(100_000);
    }

    $blueprint = [
        '$schema' => 'https://playground.wordpress.net/blueprint-schema.json',
        'steps' => [],
    ];

    if ($current === 'multisite') {
        $blueprint['steps'][] = ['step' => 'enableMultisite'];
    }

    $blueprint['steps'][] = [
        'step' => 'installPlugin',
        'pluginData' => [
            'resource' => 'vfs',
            'path' => '/e2e/.work/' . basename($initialZip),
        ],
        'options' => [
            'activate' => false,
            'targetFolderName' => 'fake-plugin',
        ],
    ];
    $blueprint['steps'][] = [
        'step' => 'wp-cli',
        'command' => 'wp plugin activate fake-plugin' . ($current === 'multisite' ? ' --network' : ''),
    ];
    $blueprint['steps'][] = [
        'step' => 'wp-cli',
        'command' => 'wp eval-file /e2e/assert.php',
    ];

    $blueprintFile = $work . '/blueprint-' . $current . '.json';
    file_put_contents($blueprintFile, json_encode($blueprint, JSON_PRETTY_PRINT));

    $command = [
        'npx', '-y', '@wp-playground/cli@latest', 'run-blueprint',
        '--blueprint=' . $blueprintFile,
        // multisite refuses a custom port, so a port-less site URL is required
        '--site-url=http://playground.test',
        '--mount=' . $e2e . '/fixtures/mu-plugins:/wordpress/wp-content/mu-plugins',
        '--mount=' . $e2e . ':/e2e',
        '--mount=' . $results . ':/results',
    ];

    say('Booting WordPress…');
    $playground = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pgPipes, $root);
    $stdout = '';
    $stderr = '';

    if (is_resource($playground)) {
        $stdout = (string) stream_get_contents($pgPipes[1]);
        $stderr = (string) stream_get_contents($pgPipes[2]);
        fclose($pgPipes[1]);
        fclose($pgPipes[2]);
        $exitCode = proc_close($playground);
    } else {
        $exitCode = 1;
    }

    // stop the fake server
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_terminate($server);
    proc_close($server);

    if ($exitCode !== 0) {
        say('Playground exited with code ' . $exitCode);
        say($stderr !== '' ? $stderr : $stdout);
        $failures[] = $current . ': Playground failed';

        continue;
    }

    $resultFile = $results . '/result.json';

    // a missing results file is a failure, never a silent pass
    if (!file_exists($resultFile)) {
        say('No results were written.');
        say($stderr !== '' ? $stderr : $stdout);
        $failures[] = $current . ': no results file';

        continue;
    }

    $result = json_decode((string) file_get_contents($resultFile), true);
    $steps = $result['steps'] ?? [];
    $state = json_decode((string) @file_get_contents($stateFile), true) ?: [];
    $requests = $state['requests'] ?? [];
    $activations = $state['activations'] ?? [];

    foreach ($result['errors'] ?? [] as $error) {
        $failures[] = $current . ': ' . $error;
        say('  FAIL  ' . $error);
    }

    if ($scenario !== 'valid') {
        say('  (scenario assertions)');
        check($failures, $current . ': no update installed', ($steps['version_after'] ?? null) === '1.0.0');

        if ($scenario === 'expired') {
            // the upgrade notice used to come from a constant defined on 'init',
            // which fataled whenever an update check ran earlier
            check(
                $failures,
                $current . ': injected upgrade notice is shown',
                ($steps['upgrade_notice'] ?? null) === 'INJECTED_UPGRADE_NOTICE',
                var_export($steps['upgrade_notice'] ?? null, true)
            );
            check(
                $failures,
                $current . ': update is still offered',
                ($steps['update_offered'] ?? null) === true
            );
            check(
                $failures,
                $current . ': download is withheld',
                ($steps['offered_package'] ?? '') === ''
            );
        }

        if ($scenario === 'invalid-key') {
            check(
                $failures,
                $current . ': license is not reported as activated',
                ($steps['license_activated'] ?? null) === false
            );
        }

        continue;
    }

    check(
        $failures,
        $current . ': multisite flag',
        ($steps['is_multisite'] ?? null) === ($current === 'multisite')
    );
    check($failures, $current . ': version before is 1.0.0', ($steps['version_before'] ?? null) === '1.0.0');
    check($failures, $current . ': license activated', ($steps['license_activated'] ?? null) === true);
    check($failures, $current . ': update offered', ($steps['update_offered'] ?? null) === true);
    check(
        $failures,
        $current . ': offered version is ' . OFFERED_VERSION,
        ($steps['offered_version'] ?? null) === OFFERED_VERSION
    );
    check(
        $failures,
        $current . ': slug is the plugin directory, not the basename',
        ($steps['offered_slug'] ?? null) === 'fake-plugin',
        'got ' . var_export($steps['offered_slug'] ?? null, true)
    );
    check(
        $failures,
        $current . ': injected string is used',
        ($steps['injected_unknown_error'] ?? null) === 'INJECTED_UNKNOWN_ERROR'
    );
    check(
        $failures,
        $current . ': update installed (version is ' . OFFERED_VERSION . ')',
        ($steps['version_after'] ?? null) === OFFERED_VERSION,
        'got ' . var_export($steps['version_after'] ?? null, true)
    );
    check(
        $failures,
        $current . ': installed into the correct directory',
        ($steps['installed_in_correct_directory'] ?? null) === true
    );

    // the canonical identity, asserted as the server actually received it
    $identities = array_values(array_unique(array_filter(array_column($requests, 'platform'))));

    check(
        $failures,
        $current . ': exactly one identity was sent',
        count($identities) === 1,
        implode(', ', $identities)
    );
    check(
        $failures,
        $current . ': identity ends with a slash',
        $identities !== [] && str_ends_with((string) $identities[0], '/'),
        (string) ($identities[0] ?? '')
    );
    check(
        $failures,
        $current . ': exactly one activation consumed',
        count($activations) === 1,
        (string) count($activations)
    );
}

say('');

if ($failures !== []) {
    say('FAILED (' . count($failures) . ')');

    foreach ($failures as $failure) {
        say(' - ' . $failure);
    }

    exit(1);
}

say('All end-to-end checks passed.');
exit(0);
