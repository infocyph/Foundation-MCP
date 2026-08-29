<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Tests\Support;

final class TempProject
{
    /**
     * @param array<string, mixed> $composer
     * @param list<string> $directories
     * @param array<string, string> $files
     */
    public static function create(array $composer, array $directories = [], array $files = []): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'foundation-mcp-'.bin2hex(random_bytes(8));

        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            throw new \RuntimeException('Unable to create temporary project.');
        }

        file_put_contents(
            $root.DIRECTORY_SEPARATOR.'composer.json',
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        foreach ($directories as $directory) {
            $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);

            if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
                throw new \RuntimeException('Unable to create temporary directory.');
            }
        }

        foreach ($files as $relative => $content) {
            $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $parent = dirname($path);

            if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new \RuntimeException('Unable to create temporary file directory.');
            }

            file_put_contents($path, $content);
        }

        return $root;
    }

    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            self::remove($path.DIRECTORY_SEPARATOR.$entry);
        }

        rmdir($path);
    }
}
