<?php
declare(strict_types=1);

namespace epiphyt\Plugin_Updater\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use epiphyt\Plugin_Updater\Config;
use epiphyt\Plugin_Updater\Strings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeConfig(array $overrides = []): Config
    {
        $args = array_merge([
            'plugin_basename' => 'my-plugin/my-plugin.php',
            'plugin_key' => 'my_plugin',
            'product_id' => 'My Plugin',
            'update_slug' => 'my-plugin-package',
            'license_option_name' => 'my_plugin_license_options',
        ], $overrides);

        return new Config(...$args);
    }

    public function testRequiresMandatoryData(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeConfig(['plugin_key' => '']);
    }

    public function testDerivesPluginSlugFromBasename(): void
    {
        self::assertSame('my-plugin', $this->makeConfig()->get_plugin_slug());
    }

    public function testDerivesPluginSlugForSingleFilePlugin(): void
    {
        $config = $this->makeConfig(['plugin_basename' => 'single.php']);

        self::assertSame('single', $config->get_plugin_slug());
    }

    public function testDerivesConstantNamesFromPluginKey(): void
    {
        $config = $this->makeConfig();

        self::assertSame('MY_PLUGIN_LICENSE_EMAIL', $config->get_license_email_constant());
        self::assertSame('MY_PLUGIN_LICENSE_KEY', $config->get_license_key_constant());
    }

    public function testProductNameDefaultsToProductId(): void
    {
        self::assertSame('My Plugin', $this->makeConfig()->get_product_name());
    }

    public function testOptionNamesArePluginScoped(): void
    {
        $config = $this->makeConfig();

        self::assertSame('epiphyt_updater_my_plugin_license_response', $config->get_license_response_option_name());
        self::assertSame('epiphyt_updater_my_plugin_deactivation_response', $config->get_deactivation_response_option_name());
        self::assertSame('epiphyt_updater_my_plugin_update_check', $config->get_update_transient_name());
        self::assertSame('epiphyt_updater_my_plugin_request_time', $config->get_request_time_transient_name());
    }

    /**
     * The site identity must always carry a trailing slash, whichever form
     * WordPress returns.
     */
    #[DataProvider('provideHomeUrls')]
    public function testInstanceIdAlwaysEndsWithSlash(string $home_url): void
    {
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('home_url')->justReturn($home_url);
        Functions\when('trailingslashit')->alias(
            static fn (string $value): string => rtrim($value, '/\\') . '/'
        );

        self::assertSame('https://example.com/', $this->makeConfig()->get_instance_id());
    }

    public static function provideHomeUrls(): array
    {
        return [
            'without slash' => ['https://example.com'],
            'with slash' => ['https://example.com/'],
        ];
    }

    /**
     * On a multisite the network URL is used, never the per-site home URL: the
     * license belongs to the whole network and must have one identity.
     */
    public function testInstanceIdUsesNetworkUrlOnMultisite(): void
    {
        Functions\when('is_multisite')->justReturn(true);
        Functions\when('network_site_url')->justReturn('https://network.example.com/');
        Functions\when('home_url')->justReturn('https://subsite.example.com');
        Functions\when('trailingslashit')->alias(
            static fn (string $value): string => rtrim($value, '/\\') . '/'
        );

        $config = $this->makeConfig();

        self::assertSame('https://network.example.com/', $config->get_instance_id());
        self::assertSame($config->get_instance_id(), $config->get_platform());
    }

    public function testSettingsUrlResolvesCallableLazily(): void
    {
        $calls = 0;
        $config = $this->makeConfig([
            'settings_url' => static function () use (&$calls): string {
                ++$calls;

                return 'https://example.com/settings?call=' . $calls;
            },
        ]);

        self::assertSame(0, $calls, 'The callable must not be resolved in the constructor.');
        self::assertSame('https://example.com/settings?call=1', $config->get_settings_url());
        self::assertSame('https://example.com/settings?call=2', $config->get_settings_url());
    }

    public function testSettingsUrlAcceptsPlainString(): void
    {
        $config = $this->makeConfig(['settings_url' => 'https://example.com/settings']);

        self::assertSame('https://example.com/settings', $config->get_settings_url());
    }

    public function testUpdateUrlDefaultsToProductionServer(): void
    {
        self::assertSame(Config::DEFAULT_UPDATE_URL, $this->makeConfig()->get_update_url());
    }

    public function testUpdateSlugHasNoDebugSuffixByDefault(): void
    {
        self::assertSame('my-plugin-package', $this->makeConfig()->get_update_slug());
    }

    public function testGetStringAppliesFilters(): void
    {
        $config = $this->makeConfig([
            'strings' => new Strings([Strings::UNKNOWN_ERROR => 'Injected.']),
        ]);

        Functions\when('apply_filters')->alias(
            static fn (string $hook, string $value): string => $value
        );

        self::assertSame('Injected.', $config->get_string(Strings::UNKNOWN_ERROR));
    }

    public function testLegacyOptionNamesAreKeyed(): void
    {
        $config = $this->makeConfig([
            'legacy_option_names' => [
                'license_response' => ['epiphyt_license_response'],
            ],
        ]);

        self::assertSame(['epiphyt_license_response'], $config->get_legacy_option_names('license_response'));
        self::assertSame([], $config->get_legacy_option_names('update'));
    }
}
