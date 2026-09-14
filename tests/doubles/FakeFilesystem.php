<?php

declare(strict_types=1);

namespace epiphyt\Plugin_Updater\Tests\Doubles;

use WP_Filesystem_Base;

/**
 * A WP_Filesystem implementation working on the real filesystem.
 *
 * Only the handful of methods the package uses are implemented, with the same
 * semantics WP_Filesystem_Direct has – most importantly move(), which refuses
 * an existing destination and delegates to rename().
 */
final class FakeFilesystem extends WP_Filesystem_Base
{
    /** @var string[] */
    public array $calls = [];

    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * @return array<string, array<string, string>>|false
     */
    public function dirlist(string $path, bool $includeHidden = true, bool $recursive = false): array|false
    {
        $this->calls[] = 'dirlist';

        if (!is_dir($path)) {
            return false;
        }

        $list = [];

        foreach (scandir(rtrim($path, '/')) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $list[$entry] = ['name' => $entry];
        }

        return $list;
    }

    public function mkdir(string $path): bool
    {
        $this->calls[] = 'mkdir';

        return mkdir(rtrim($path, '/'), 0o777, true);
    }

    public function move(string $source, string $destination, bool $overwrite = false): bool
    {
        $this->calls[] = 'move';

        if (!$overwrite && file_exists($destination)) {
            return false;
        }

        return @rename($source, $destination);
    }

    public function delete(string $path, bool $recursive = false, string|false $type = false): bool
    {
        $this->calls[] = 'delete';

        if (is_file($path)) {
            return unlink($path);
        }

        if (!is_dir($path)) {
            return false;
        }

        foreach (scandir(rtrim($path, '/')) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->delete(rtrim($path, '/') . '/' . $entry, true);
        }

        return rmdir(rtrim($path, '/'));
    }
}
