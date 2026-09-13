<?php
declare(strict_types=1);

namespace epiphyt\Plugin_Updater\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use epiphyt\Plugin_Updater\Strings;
use PHPUnit\Framework\Attributes\CoversClass;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

#[CoversClass(Strings::class)]
final class StringsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('esc_html')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testFallsBackToEnglishDefaults(): void
    {
        $strings = new Strings();

        self::assertSame('Unknown error.', $strings->get(Strings::UNKNOWN_ERROR));
    }

    public function testOverridesDefault(): void
    {
        $strings = new Strings([Strings::UNKNOWN_ERROR => 'Unbekannter Fehler.']);

        self::assertSame('Unbekannter Fehler.', $strings->get(Strings::UNKNOWN_ERROR));
    }

    public function testKeepsDefaultsForKeysNotOverridden(): void
    {
        $strings = new Strings([Strings::UNKNOWN_ERROR => 'Unbekannter Fehler.']);

        self::assertSame('Invalid request.', $strings->get(Strings::INVALID_REQUEST));
    }

    public function testUnknownKeyIsRejected(): void
    {
        Functions\expect('_doing_it_wrong')->once();

        $strings = new Strings(['not_a_key' => 'value']);

        self::assertSame('', $strings->get('not_a_key'));
    }

    public function testWithReturnsNewInstance(): void
    {
        $strings = new Strings();
        $other = $strings->with([Strings::UNKNOWN_ERROR => 'Changed.']);

        self::assertNotSame($strings, $other);
        self::assertSame('Unknown error.', $strings->get(Strings::UNKNOWN_ERROR));
        self::assertSame('Changed.', $other->get(Strings::UNKNOWN_ERROR));
    }

    public function testAllReturnsEveryKey(): void
    {
        $all = (new Strings())->all();

        self::assertArrayHasKey(Strings::UPGRADE_NOTICE, $all);
        self::assertCount(12, $all);
    }
}
