<?php
declare(strict_types=1);

namespace epiphyt\Plugin_Updater\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use epiphyt\Plugin_Updater\Config;
use epiphyt\Plugin_Updater\License;
use epiphyt\Plugin_Updater\Storage;
use epiphyt\Plugin_Updater\Tests\Doubles\FakeLicenseClient;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(License::class)]
final class LicenseTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        FakeLicenseClient::$calls = [];
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeLicense(): License
    {
        $config = new Config(
            plugin_basename: 'my-plugin/my-plugin.php',
            plugin_key: 'my_plugin',
            product_id: 'My Plugin',
            update_slug: 'my-plugin',
            license_option_name: 'my_plugin_license_options',
            license_client: FakeLicenseClient::class
        );

        return new License($config, new Storage($config), $config->create_license_client());
    }

    /**
     * A fresh installation has no credentials yet. Writing a failure response
     * here made every newly installed plugin report a failed license activation
     * on the very next request, before anything could have been entered.
     */
    public function testCheckWithoutCredentialsStoresNothing(): void
    {
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('get_option')->justReturn(false);
        Functions\expect('update_option')->never();
        Functions\expect('delete_option')->never();

        $this->makeLicense()->check();

        self::assertSame([], array_slice(FakeLicenseClient::$calls, 1));
    }

    /**
     * An absent response means nothing has been attempted yet, which is not a
     * failure. The original implementation agreed here – but its callers then
     * treated “not failed” as “activated”, which reported a fresh install as
     * licensed.
     */
    public function testResponseFailedIsFalseForEmptyResponse(): void
    {
        self::assertFalse(License::response_failed([]));
    }

    public function testResponseFailedIsTrueWithoutSuccess(): void
    {
        self::assertTrue(License::response_failed(['error' => 'nope']));
    }

    /**
     * An empty string was written by the previous implementation after a
     * successful activation, and casting it to an array yields [''], which must
     * count as a failure rather than as a valid response.
     */
    public function testResponseFailedIsTrueForCastEmptyString(): void
    {
        self::assertTrue(License::response_failed((array) ''));
    }

    public function testResponseFailedIsFalseOnSuccess(): void
    {
        self::assertFalse(License::response_failed(['success' => true]));
    }

    /**
     * The original truncated by string length, so a licence covering major
     * version 2 compared '1' against '2' for an update to 10.1 and reported it
     * as covered. Truncation must happen per segment.
     */
    #[DataProvider('provideVersions')]
    public function testTruncateVersion(string $version, string $reference, string $expected): void
    {
        self::assertSame($expected, License::truncate_version($version, $reference));
    }

    public static function provideVersions(): array
    {
        return [
            'same precision' => ['1.6.2', '1.5', '1.6'],
            'single segment reference' => ['10.1', '2', '10'],
            'three segments' => ['3.2.1', '1.0.0', '3.2.1'],
            'shorter candidate' => ['9', '1.2.3', '9'],
            'equal' => ['2.0', '2.0', '2.0'],
        ];
    }

    /**
     * The concrete defect the segment-wise comparison fixes.
     */
    public function testVersionTenIsNewerThanTwo(): void
    {
        $truncated = License::truncate_version('10.1', '2');

        self::assertSame(1, version_compare($truncated, '2'));
    }
}
