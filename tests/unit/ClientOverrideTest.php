<?php

declare(strict_types=1);

namespace epiphyt\Plugin_Updater\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use epiphyt\Plugin_Updater\Config;
use epiphyt\Plugin_Updater\Credentials;
use epiphyt\Plugin_Updater\License;
use epiphyt\Plugin_Updater\License_Client;
use epiphyt\Plugin_Updater\License_Client_Interface;
use epiphyt\Plugin_Updater\Plugin_Updater;
use epiphyt\Plugin_Updater\Storage;
use epiphyt\Plugin_Updater\Tests\Doubles\FakeLicenseClient;
use epiphyt\Plugin_Updater\Tests\Doubles\FakeUpdateClient;
use epiphyt\Plugin_Updater\Tests\Doubles\NotAClient;
use epiphyt\Plugin_Updater\Update_Client;
use epiphyt\Plugin_Updater\Update_Client_Interface;
use InvalidArgumentException;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WP_Error;

#[CoversClass(Config::class)]
#[CoversClass(Plugin_Updater::class)]
final class ClientOverrideTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        FakeUpdateClient::$instantiations = 0;
        FakeLicenseClient::$calls = [];
        Functions\when('esc_html')->returnArg();
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('wp_doing_cron')->justReturn(false);
        Functions\when('wp_next_scheduled')->justReturn(123);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeConfig(array $overrides = []): Config
    {
        return new Config(...array_merge([
            'plugin_basename' => 'my-plugin/my-plugin.php',
            'plugin_key' => 'my_plugin',
            'product_id' => 'My Plugin',
            'update_slug' => 'my-plugin',
            'license_option_name' => 'my_plugin_license_options',
        ], $overrides));
    }

    private function makeLicense(Config $config): License
    {
        return new License($config, new Storage($config), new License_Client($config));
    }

    public function testDefaultsToTheBuiltInClients(): void
    {
        $config = $this->makeConfig();

        self::assertInstanceOf(License_Client::class, $config->create_license_client());
        self::assertInstanceOf(Update_Client::class, $config->create_update_client($this->makeLicense($config)));
    }

    public function testAcceptsAClassName(): void
    {
        $config = $this->makeConfig([
            'update_client' => FakeUpdateClient::class,
            'license_client' => FakeLicenseClient::class,
        ]);

        self::assertInstanceOf(FakeUpdateClient::class, $config->create_update_client($this->makeLicense($config)));
        self::assertInstanceOf(FakeLicenseClient::class, $config->create_license_client());
    }

    /**
     * The class receives the configuration and, for the update client, the
     * license handler – so it can decide what to send and whether it may.
     */
    public function testClassNameReceivesTheDocumentedConstructorArguments(): void
    {
        $config = $this->makeConfig([
            'update_client' => FakeUpdateClient::class,
            'license_client' => FakeLicenseClient::class,
        ]);
        $license = $this->makeLicense($config);
        $client = $config->create_update_client($license);

        self::assertInstanceOf(FakeUpdateClient::class, $client);
        self::assertSame($license, $client->get_license());
        self::assertSame(
            'My Plugin',
            $client->get_metadata()['product_id'],
            'The client did not receive the configuration.'
        );

        $config->create_license_client();

        self::assertSame(['construct:my_plugin'], FakeLicenseClient::$calls);
    }

    public function testAcceptsAFactoryCallable(): void
    {
        $config = $this->makeConfig([
            'update_client' => static fn (Config $config, License $license): Update_Client_Interface
                => new FakeUpdateClient($config, $license),
            'license_client' => static fn (Config $config): License_Client_Interface
                => new FakeLicenseClient($config),
        ]);

        self::assertInstanceOf(FakeUpdateClient::class, $config->create_update_client($this->makeLicense($config)));
        self::assertInstanceOf(FakeLicenseClient::class, $config->create_license_client());
    }

    /**
     * A typo in the class name has to fail while the plugin boots, not silently
     * weeks later when an update would have been offered.
     */
    public function testRejectsAnUnknownClassImmediately(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $this->makeConfig(['update_client' => 'epiphyt\Plugin_Updater\No_Such_Client']);
    }

    public function testRejectsAClassNotImplementingTheUpdateInterface(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Update_Client_Interface::class);

        $this->makeConfig(['update_client' => NotAClient::class]);
    }

    public function testRejectsAClassNotImplementingTheLicenseInterface(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(License_Client_Interface::class);

        $this->makeConfig(['license_client' => NotAClient::class]);
    }

    public function testRejectsAFactoryReturningTheWrongType(): void
    {
        $config = $this->makeConfig([
            'license_client' => static fn (): object => new NotAClient(),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(License_Client_Interface::class);

        $config->create_license_client();
    }

    /**
     * The whole point of the override: the facade has to route through the
     * custom implementation, not merely accept it.
     */
    public function testUpdaterUsesTheCustomUpdateClient(): void
    {
        $updater = new Plugin_Updater($this->makeConfig(['update_client' => FakeUpdateClient::class]));

        self::assertSame(1, FakeUpdateClient::$instantiations);
        self::assertSame(['product_id' => 'My Plugin', 'version' => '9.9.9'], $updater->get_metadata());
    }

    public function testUpdaterUsesTheCustomLicenseClient(): void
    {
        Functions\when('update_option')->justReturn(true);

        $updater = new Plugin_Updater($this->makeConfig(['license_client' => FakeLicenseClient::class]));
        $result = $updater->activate_license(new Credentials('user@example.com', 'ABC-123'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('Refused by the custom server.', $result->get_error_message());
        self::assertSame(['construct:my_plugin', 'activate'], FakeLicenseClient::$calls);
    }
}
