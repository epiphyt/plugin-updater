<?php
declare(strict_types=1);

namespace epiphyt\Plugin_Updater\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use epiphyt\Plugin_Updater\Config;
use epiphyt\Plugin_Updater\Package_Mover;
use epiphyt\Plugin_Updater\Storage;
use epiphyt\Plugin_Updater\Update_Checker;
use epiphyt\Plugin_Updater\Update_Response;
use epiphyt\Plugin_Updater\License;
use epiphyt\Plugin_Updater\Tests\Doubles\FakeFilesystem;
use epiphyt\Plugin_Updater\Tests\Doubles\FakeLicenseClient;
use epiphyt\Plugin_Updater\Tests\Doubles\FakeUpdateClient;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Package_Mover::class)]
final class PackageMoverTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private string $work = '';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('trailingslashit')->alias(
            static fn (string $value): string => rtrim($value, '/\\') . '/'
        );
        Functions\when('untrailingslashit')->alias(
            static fn (string $value): string => rtrim($value, '/\\')
        );

        $this->work = sys_get_temp_dir() . '/epiphyt-mover-' . bin2hex(random_bytes(6));

        mkdir($this->work, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->work);
        unset($GLOBALS['wp_filesystem']);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }

    private function makeMover(string $basename = 'my-plugin/my-plugin.php'): Package_Mover
    {
        $config = new Config(
            plugin_basename: $basename,
            plugin_key: 'my_plugin',
            product_id: 'My Plugin',
            update_slug: 'my-plugin',
            license_option_name: 'my_plugin_license_options',
            update_client: FakeUpdateClient::class,
            license_client: FakeLicenseClient::class
        );
        $storage = new Storage($config);
        $license = new License($config, $storage, $config->create_license_client());

        return new Package_Mover(
            $config,
            new Update_Checker(
                $config,
                $storage,
                $config->create_update_client($license),
                new Update_Response($config, $license)
            )
        );
    }

    /**
     * @param	array<int, string>	$files
     */
    private function makeSource(string $path, array $files = ['my-plugin.php']): string
    {
        mkdir($path, 0o777, true);

        foreach ($files as $file) {
            file_put_contents($path . '/' . $file, '<?php');
        }

        return $path;
    }

    private function args(string $plugin = 'my-plugin/my-plugin.php'): array
    {
        return ['plugin' => $plugin, 'type' => 'plugin', 'action' => 'update'];
    }

    /**
     * The regular case: the ZIP carries a single directory whose name does not
     * match the installed plugin directory.
     */
    public function testRenamesTheExtractedDirectory(): void
    {
        $GLOBALS['wp_filesystem'] = new FakeFilesystem();
        $remote = $this->work . '/upgrade/my-plugin-dev';
        $source = $this->makeSource($remote . '/my-plugin-dev');

        $result = $this->makeMover()->move($source . '/', $remote . '/', null, $this->args());

        self::assertSame($remote . '/my-plugin/', $result);
        self::assertFileExists($remote . '/my-plugin/my-plugin.php');
        self::assertDirectoryDoesNotExist($remote . '/my-plugin-dev');
    }

    /**
     * The defect this covers: a ZIP holding the plugin files at its root leaves
     * WP_Upgrader with the working directory itself as the source, and renaming
     * a directory into one of its own children fails. The update then landed in
     * a directory named after the downloaded package while WordPress deleted the
     * real plugin directory, which installed the plugin twice.
     */
    public function testWrapsFilesExtractedToTheWorkingDirectory(): void
    {
        $GLOBALS['wp_filesystem'] = new FakeFilesystem();
        $remote = $this->makeSource(
            $this->work . '/upgrade/my-plugin-dev',
            ['my-plugin.php', 'readme.txt']
        );

        $result = $this->makeMover()->move($remote . '/', $remote . '/', null, $this->args());

        self::assertSame($remote . '/my-plugin/', $result);
        self::assertFileExists($remote . '/my-plugin/my-plugin.php');
        self::assertFileExists($remote . '/my-plugin/readme.txt');
        self::assertFileDoesNotExist($remote . '/my-plugin.php');
    }

    public function testKeepsAMatchingDirectoryUntouched(): void
    {
        $GLOBALS['wp_filesystem'] = new FakeFilesystem();
        $remote = $this->work . '/upgrade/my-plugin';
        $source = $this->makeSource($remote . '/my-plugin');

        $result = $this->makeMover()->move($source . '/', $remote . '/', null, $this->args());

        self::assertSame($source . '/', $result);
        self::assertFileExists($source . '/my-plugin.php');
    }

    /**
     * A leftover directory of a previous, aborted update must not make the move
     * fail silently.
     */
    public function testReplacesAnExistingTargetDirectory(): void
    {
        $GLOBALS['wp_filesystem'] = new FakeFilesystem();
        $remote = $this->work . '/upgrade/my-plugin-dev';
        $source = $this->makeSource($remote . '/my-plugin-dev');

        $this->makeSource($remote . '/my-plugin', ['stale.php']);

        $result = $this->makeMover()->move($source . '/', $remote . '/', null, $this->args());

        self::assertSame($remote . '/my-plugin/', $result);
        self::assertFileExists($remote . '/my-plugin/my-plugin.php');
        self::assertFileDoesNotExist($remote . '/my-plugin/stale.php');
    }

    /**
     * A single-file plugin is installed into the plugin directory itself, so
     * wrapping it into a directory would move it out of WordPress' reach.
     */
    public function testLeavesASingleFilePluginAlone(): void
    {
        $GLOBALS['wp_filesystem'] = new FakeFilesystem();
        $remote = $this->makeSource($this->work . '/upgrade/single', ['single.php']);

        $result = $this->makeMover()->move($remote . '/', $remote . '/', null, $this->args('single.php'));

        self::assertSame($remote . '/', $result);
    }

    public function testIgnoresOtherPlugins(): void
    {
        $GLOBALS['wp_filesystem'] = new FakeFilesystem();
        $remote = $this->work . '/upgrade/other';
        $source = $this->makeSource($remote . '/other');

        $result = $this->makeMover()->move($source . '/', $remote . '/', null, $this->args('other/other.php'));

        self::assertSame($source . '/', $result);
        self::assertDirectoryExists($remote . '/other');
    }

    public function testIgnoresANonStringSource(): void
    {
        $GLOBALS['wp_filesystem'] = new FakeFilesystem();

        self::assertNull($this->makeMover()->move(null, $this->work, null, $this->args()));
    }
}
