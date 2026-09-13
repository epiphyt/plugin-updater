<?php
/**
 * Plugin Name: E2E local HTTP
 * Description: Allows HTTP requests to the loopback fake server during the end-to-end suite.
 *
 * wp_http_validate_url() rejects the fake server on two counts: it resolves to a
 * loopback address, and it listens on a port outside the safe list. Both gates
 * are lifted here, scoped strictly to loopback hosts, so nothing else becomes
 * reachable. This only affects the test environment; real update servers are
 * public hosts on standard ports.
 */
declare(strict_types=1);

/**
 * Check whether a host is a loopback address.
 */
function epiphyt_e2e_is_loopback(string $host): bool
{
    return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
}

add_filter(
    'http_request_host_is_external',
    static function (bool $external, string $host): bool {
        return epiphyt_e2e_is_loopback($host) ? true : $external;
    },
    10,
    2
);

add_filter(
    'http_allowed_safe_ports',
    static function (array $ports, string $host, string $url): array {
        if (!epiphyt_e2e_is_loopback($host)) {
            return $ports;
        }

        $port = parse_url($url, PHP_URL_PORT);

        if (is_int($port)) {
            $ports[] = $port;
        }

        return $ports;
    },
    10,
    3
);
