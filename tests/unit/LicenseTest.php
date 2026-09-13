<?php
declare(strict_types=1);

namespace epiphyt\Plugin_Updater\Tests\Unit;

use epiphyt\Plugin_Updater\License;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(License::class)]
final class LicenseTest extends TestCase
{
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
