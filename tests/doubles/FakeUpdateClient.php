<?php

declare(strict_types=1);

namespace epiphyt\Plugin_Updater\Tests\Doubles;

use epiphyt\Plugin_Updater\Config;
use epiphyt\Plugin_Updater\License;
use epiphyt\Plugin_Updater\Update_Client_Interface;
use WP_Error;

/**
 * An update client talking to something that is not wp-update-server.
 *
 * Mirrors the constructor signature the package uses when it instantiates a
 * configured class name.
 */
final class FakeUpdateClient implements Update_Client_Interface
{
    public static int $instantiations = 0;

    private Config $config;

    private License $license;

    public function __construct(Config $config, License $license)
    {
        ++self::$instantiations;
        $this->config = $config;
        $this->license = $license;
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function get_metadata(): array|WP_Error
    {
        return [
            'product_id' => $this->config->get_product_id(),
            'version' => '9.9.9',
        ];
    }

    public function get_license(): License
    {
        return $this->license;
    }
}
