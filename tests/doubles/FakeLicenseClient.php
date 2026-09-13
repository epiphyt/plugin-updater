<?php

declare(strict_types=1);

namespace epiphyt\Plugin_Updater\Tests\Doubles;

use epiphyt\Plugin_Updater\Config;
use epiphyt\Plugin_Updater\Credentials;
use epiphyt\Plugin_Updater\License_Client_Interface;
use WP_Error;

/**
 * A license client talking to something that is not the WooCommerce Software
 * Add-on.
 */
final class FakeLicenseClient implements License_Client_Interface
{
    /** @var string[] */
    public static array $calls = [];

    public function __construct(Config $config)
    {
        self::$calls[] = 'construct:' . $config->get_plugin_key();
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function activate(Credentials $credentials): array|WP_Error
    {
        self::$calls[] = 'activate';

        return ['error' => 'Refused by the custom server.'];
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function check(Credentials $credentials): array|WP_Error
    {
        self::$calls[] = 'check';

        return ['success' => true, 'activations' => []];
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function deactivate(Credentials $credentials): array|WP_Error
    {
        self::$calls[] = 'deactivate';

        return ['success' => true];
    }
}
