<?php
declare(strict_types=1);

namespace epiphyt\Plugin_Updater\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use epiphyt\Plugin_Updater\Config;
use epiphyt\Plugin_Updater\Plugin_Updater;
use PHPUnit\Framework\Attributes\CoversClass;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

#[CoversClass(Plugin_Updater::class)]
final class PluginUpdaterTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
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

    private function makeUpdater(): Plugin_Updater
    {
        return new Plugin_Updater(new Config(
            plugin_basename: 'my-plugin/my-plugin.php',
            plugin_key: 'my_plugin',
            product_id: 'My Plugin',
            update_slug: 'my-plugin',
            license_option_name: 'my_plugin_license_options'
        ));
    }

    /**
     * The package renders strings the host plugin translated and passed in, so
     * initializing before 'init' would render them untranslated. Registration is
     * deferred rather than thrown away, so the plugin keeps working.
     */
    public function testInitBeforeInitDefersAndWarns(): void
    {
        Functions\when('did_action')->justReturn(0);
        Functions\expect('_doing_it_wrong')->once();

        Actions\expectAdded('init')->once();
        Filters\expectAdded('plugins_api')->never();
        Filters\expectAdded('pre_set_site_transient_update_plugins')->never();
        Filters\expectAdded('upgrader_source_selection')->never();

        $this->makeUpdater()->init();
    }

    public function testInitRegistersEveryHook(): void
    {
        Functions\when('did_action')->justReturn(1);
        Functions\when('is_admin')->justReturn(true);

        Filters\expectAdded('plugins_api')->once()->with(\Mockery::type('array'), 10, 3);
        Filters\expectAdded('pre_set_site_transient_update_plugins')->once();
        Filters\expectAdded('upgrader_source_selection')->once()->with(\Mockery::type('array'), 5, 4);
        Actions\expectAdded('admin_notices')->twice();
        Actions\expectAdded('network_admin_notices')->twice();
        Actions\expectAdded('after_plugin_row_my-plugin/my-plugin.php')->twice();
        Actions\expectAdded('add_option_my_plugin_license_options')->once();
        Actions\expectAdded('update_option_my_plugin_license_options')->once();
        Actions\expectAdded('add_site_option_my_plugin_license_options')->once();
        Actions\expectAdded('update_site_option_my_plugin_license_options')->once();
        Actions\expectAdded('admin_init')->once();
        Actions\expectAdded('epiphyt_updater_my_plugin_license_check')->once();

        $this->makeUpdater()->init();
    }

    public function testInitIsIdempotent(): void
    {
        Functions\when('did_action')->justReturn(1);
        Functions\when('is_admin')->justReturn(true);

        Filters\expectAdded('plugins_api')->once();

        $updater = $this->makeUpdater();
        $updater->init();
        $updater->init();
    }

    public function testConfigIsExposed(): void
    {
        self::assertSame('my-plugin', $this->makeUpdater()->get_config()->get_plugin_slug());
    }
}
