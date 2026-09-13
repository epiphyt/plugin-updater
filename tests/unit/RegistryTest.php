<?php
declare(strict_types=1);

namespace epiphyt\Plugin_Updater\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use epiphyt\Plugin_Updater\Config;
use epiphyt\Plugin_Updater\Plugin_Updater;
use epiphyt\Plugin_Updater\Registry;
use PHPUnit\Framework\Attributes\CoversClass;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

#[CoversClass(Registry::class)]
final class RegistryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('esc_html')->returnArg();
        Registry::reset();
    }

    protected function tearDown(): void
    {
        Registry::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeConfig(string $basename, string $key): Config
    {
        return new Config(
            plugin_basename: $basename,
            plugin_key: $key,
            product_id: ucfirst($key),
            update_slug: $key,
            license_option_name: $key . '_license_options'
        );
    }

    /**
     * Two plugins using this package must not share any state. The previous
     * singleton-based libraries did, so with two plugins active only the first
     * ever checked its license and both wrote to the same options.
     */
    public function testTwoPluginsGetIndependentInstances(): void
    {
        $first = Registry::register($this->makeConfig('a/a.php', 'plugin_a'));
        $second = Registry::register($this->makeConfig('b/b.php', 'plugin_b'));

        self::assertNotSame($first, $second);
        self::assertSame($first, Registry::get('a/a.php'));
        self::assertSame($second, Registry::get('b/b.php'));
    }

    public function testEachInstanceKeepsItsOwnOptionNames(): void
    {
        $first = Registry::register($this->makeConfig('a/a.php', 'plugin_a'));
        $second = Registry::register($this->makeConfig('b/b.php', 'plugin_b'));

        self::assertSame(
            'epiphyt_updater_plugin_a_license_response',
            $first->get_config()->get_license_response_option_name()
        );
        self::assertSame(
            'epiphyt_updater_plugin_b_license_response',
            $second->get_config()->get_license_response_option_name()
        );
    }

    public function testRegisteringTwiceReturnsTheSameInstance(): void
    {
        Functions\expect('_doing_it_wrong')->once();

        $first = Registry::register($this->makeConfig('a/a.php', 'plugin_a'));
        $second = Registry::register($this->makeConfig('a/a.php', 'plugin_a'));

        self::assertSame($first, $second);
        self::assertCount(1, Registry::get_all());
    }

    public function testGetReturnsNullForUnknownPlugin(): void
    {
        self::assertNull(Registry::get('nope/nope.php'));
        self::assertFalse(Registry::has('nope/nope.php'));
    }

    public function testSetAllowsInjectingADouble(): void
    {
        $double = $this->createStub(Plugin_Updater::class);

        Registry::set('a/a.php', $double);

        self::assertSame($double, Registry::get('a/a.php'));
    }
}
