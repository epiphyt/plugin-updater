<?php
declare(strict_types=1);

namespace epiphyt\Plugin_Updater\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Mechanically enforces that this package carries no translatable strings.
 *
 * The package has no text domain. Every user-facing string is translated by the
 * host plugin, with the host's text domain, and injected through Strings. A
 * translation call appearing here would either be untranslatable or would
 * silently adopt a foreign text domain, and would additionally show up in the
 * host's generated .pot as a phantom entry.
 */
final class NoTranslationFunctionsTest extends TestCase
{
    private const FORBIDDEN = [
        '__(',
        '_e(',
        '_x(',
        '_n(',
        '_nx(',
        'esc_html__(',
        'esc_html_e(',
        'esc_html_x(',
        'esc_attr__(',
        'esc_attr_e(',
        'esc_attr_x(',
        'load_plugin_textdomain(',
    ];

    public function testPackageContainsNoTranslationCalls(): void
    {
        $offenders = [];

        foreach ($this->getSourceFiles() as $file) {
            $contents = file_get_contents($file);

            self::assertIsString($contents);

            foreach (self::FORBIDDEN as $needle) {
                // match a call, not a longer identifier ending in the same name
                $pattern = '/(?<![A-Za-z0-9_])' . preg_quote($needle, '/') . '/';

                if (preg_match($pattern, $contents) === 1) {
                    $offenders[] = basename($file) . ' uses ' . $needle . ')';
                }
            }
        }

        self::assertSame([], $offenders, "This package must not contain translatable strings.");
    }

    /**
     * @return string[]
     */
    private function getSourceFiles(): array
    {
        $files = glob(__DIR__ . '/../../inc/*.php');

        self::assertIsArray($files);
        self::assertNotEmpty($files, 'No source files found to scan.');

        return $files;
    }
}
