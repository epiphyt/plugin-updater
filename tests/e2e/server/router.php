<?php
/**
 * Fake update and license server for the end-to-end suite.
 *
 * Implements the two APIs the package speaks:
 * - the update server (action=get_metadata / action=download)
 * - the WooCommerce Software Add-on API (request=check/activation/deactivation)
 *
 * The behaviour is driven by the EPIPHYT_E2E_SCENARIO environment variable, so
 * failure paths are covered rather than only the happy path.
 *
 * Activation state is kept in a JSON file so activation counts can be asserted.
 */
declare(strict_types=1);

const VALID_EMAIL = 'licensee@example.com';
const VALID_KEY = 'VALID-LICENSE-KEY';
const LICENSED_VERSION = '1.1';
const OFFERED_VERSION = '1.1.0';
const ACTIVATION_LIMIT = 2;

$scenario = getenv('EPIPHYT_E2E_SCENARIO') ?: 'valid';
$stateFile = __DIR__ . '/state.json';
$packageDir = __DIR__ . '/packages';

/**
 * Read the persisted server state.
 */
function read_state(string $file): array
{
    if (!file_exists($file)) {
        return ['activations' => [], 'requests' => []];
    }

    $state = json_decode((string) file_get_contents($file), true);

    return is_array($state) ? $state : ['activations' => [], 'requests' => []];
}

/**
 * Persist the server state.
 */
function write_state(string $file, array $state): void
{
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT));
}

/**
 * Record a request for later assertions.
 */
function record(string $file, array $params): void
{
    $state = read_state($file);
    $state['requests'][] = [
        'instance' => $params['instance'] ?? null,
        'platform' => $params['platform'] ?? null,
        'product_id' => $params['product_id'] ?? null,
        'request' => $params['request'] ?? ($params['action'] ?? null),
        'time' => time(),
    ];
    write_state($file, $state);
}

/**
 * Send a JSON response and stop.
 */
function send_json(array $payload): never
{
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

/**
 * Check whether the given credentials are valid.
 */
function credentials_valid(array $params): bool
{
    $email = $params['email'] ?? $params['license_email'] ?? '';
    $key = $params['license_key'] ?? '';

    return $email === VALID_EMAIL && $key === VALID_KEY;
}

$params = $_REQUEST;
record($stateFile, $params);

if ($scenario === 'server-500') {
    http_response_code(500);
    echo 'Internal Server Error';
    exit;
}

if ($scenario === 'malformed-json') {
    header('Content-Type: application/json');
    echo '{not valid json';
    exit;
}

// ---------------------------------------------------------------- license API
if (isset($params['wc-api']) || isset($params['request'])) {
    $state = read_state($stateFile);
    $request = $params['request'] ?? 'check';
    $instance = $params['platform'] ?? $params['instance'] ?? '';

    if (!credentials_valid($params) || $scenario === 'invalid-key') {
        send_json([
            'error' => 'Invalid license key.',
            'code' => 101,
            'success' => false,
        ]);
    }

    if ($request === 'activation') {
        if ($scenario === 'activation-limit-reached'
            || count($state['activations']) >= ACTIVATION_LIMIT
        ) {
            send_json([
                'error' => 'No activations remaining.',
                'code' => 104,
                'success' => false,
            ]);
        }

        if (!isset($state['activations'][$instance])) {
            $state['activations'][$instance] = [
                'activation_platform' => $instance,
                'instance' => $instance,
                'software_version' => $scenario === 'expired' ? '1.0' : LICENSED_VERSION,
            ];
            write_state($stateFile, $state);
        }

        send_json(['activated' => true, 'success' => true, 'message' => 'Activated.']);
    }

    if ($request === 'deactivation') {
        // a real deactivation without an instance removes every activation;
        // the package always sends one, which this asserts
        if ($instance === '') {
            $state['activations'] = [];
        } else {
            unset($state['activations'][$instance]);
        }

        write_state($stateFile, $state);
        send_json(['reset' => true, 'success' => true]);
    }

    send_json([
        'activations' => array_values($state['activations']),
        'success' => true,
    ]);
}

/**
 * Check whether an instance holds an activation covering a version.
 *
 * Mirrors Epiphyt_Server::isValidLicense(): the real update server only hands
 * out a download when the requesting platform is among the activations and the
 * licensed version covers the offered one.
 */
function license_covers(array $state, string $instance, string $version): bool
{
    $normalized = rtrim($instance, '/') . '/';

    foreach ($state['activations'] as $activation) {
        $platform = rtrim((string) ($activation['activation_platform'] ?? ''), '/') . '/';

        if ($platform !== $normalized) {
            continue;
        }

        $licensed = (string) ($activation['software_version'] ?? '');
        $segments = count(explode('.', $licensed));
        $truncated = implode('.', array_slice(explode('.', $version), 0, $segments));

        return version_compare($truncated, $licensed, '<=');
    }

    return false;
}

// ----------------------------------------------------------------- update API
$action = $params['action'] ?? '';
$platform = $params['platform'] ?? '';
$licensed = credentials_valid($params)
    && $scenario !== 'invalid-key'
    && license_covers(read_state($stateFile), (string) $platform, OFFERED_VERSION);

if ($action === 'download') {
    if (!$licensed) {
        http_response_code(403);
        echo 'Sorry, your license is not valid.';
        exit;
    }

    $zip = $packageDir . '/fake-plugin.zip';

    if (!file_exists($zip)) {
        http_response_code(404);
        echo 'Package not found.';
        exit;
    }

    header('Content-Type: application/zip');
    // the package is named after the update slug, never after the directory the
    // plugin is installed in – WordPress derives its working directory from this
    // name, so the two must be allowed to differ
    header('Content-Disposition: attachment; filename="fake-plugin-dev.zip"');
    header('Content-Length: ' . filesize($zip));
    readfile($zip);
    exit;
}

if ($action === 'get_metadata') {
    $base = 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
    $metadata = [
        'author' => 'Epiphyt',
        'author_homepage' => 'https://epiph.yt/',
        'homepage' => 'https://example.com/fake-plugin',
        'icons' => [],
        'name' => 'Fake Plugin',
        'requires' => '6.5',
        'sections' => ['changelog' => '<p>Fixture changelog.</p>'],
        'slug' => 'fake-plugin',
        'tested' => '6.9',
        'version' => OFFERED_VERSION,
    ];

    // the real server only includes the download URL for a valid license
    if ($licensed) {
        $metadata['download_url'] = $base . '/?action=download';
    }

    send_json($metadata);
}

http_response_code(400);
echo 'Unknown action.';
