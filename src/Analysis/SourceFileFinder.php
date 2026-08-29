<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Analysis;

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Composer\InstalledPackage;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Project\SourceRoots;
use Infocyph\FoundationMcp\Security\PathPolicy;
use Infocyph\FoundationMcp\Security\SecretPolicy;
use RuntimeException;

final readonly class SourceFileFinder
{
    private const array EXCLUDED_SEGMENTS = [
        '.git',
        'build',
        'coverage',
        'dist',
        'node_modules',
        'temp',
        'tmp',
    ];

    private const int MAX_DIRECTORIES = 10_000;

    private const int MAX_ENTRIES = 100_000;

    private const int MAX_FILES = 20_000;

    private const int MAX_PACKAGE_AUTOLOAD_PATHS = 1_024;

    public function __construct(
        private Project $project,
        private ComposerInspector $composer,
    ) {}

    /** @return array<string, string> relative path => metadata state */
    public function package(string $package): array
    {
        $installed = $this->composer->package($package);

        if ($installed?->installPath === null) {
            throw new RuntimeException('Package is not installed with a source root.');
        }

        $paths = new PathPolicy($this->project->root, [$package => $installed->installPath]);
        $roots = $this->packageRoots($installed, $paths);

        return $this->collect($roots, $installed->installPath);
    }

    /** @return array<string, string> relative path => metadata state */
    public function project(): array
    {
        return $this->collect(SourceRoots::discover($this->project)->all(), $this->project->root);
    }

    /** @param array<string, string> $files */
    private function addFile(array &$files, string $path, string $base, SecretPolicy $secrets): void
    {
        $relative = $this->relative($path, $base);

        if (
            $relative === null
            || $this->excluded($relative)
            || $secrets->denied($relative)
            || strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'php'
        ) {
            return;
        }

        $size = filesize($path);
        $mtime = filemtime($path);
        $ctime = filectime($path);

        $files[$relative] = sprintf(
            '%d:%d:%d',
            $size === false ? -1 : $size,
            $mtime === false ? -1 : $mtime,
            $ctime === false ? -1 : $ctime,
        );

        if (count($files) > self::MAX_FILES) {
            throw new RuntimeException('Source discovery exceeds the 20,000 PHP file limit. Narrow the analyzed source roots.');
        }
    }

    /**
     * @param list<string> $roots
     * @return array<string, string>
     */
    private function collect(array $roots, string $base): array
    {
        $files = [];
        $secrets = new SecretPolicy();
        $visitedDirectories = 0;
        $visitedEntries = 0;

        foreach ($roots as $root) {
            if (is_file($root)) {
                $this->addFile($files, $root, $base, $secrets);

                continue;
            }

            if (!is_dir($root)) {
                continue;
            }

            $stack = [$root];

            while (($directory = array_pop($stack)) !== null) {
                if (++$visitedDirectories > self::MAX_DIRECTORIES) {
                    throw new RuntimeException('Source discovery exceeds the 10,000 directory limit. Narrow the analyzed source roots.');
                }

                $handle = $this->openDirectory($directory);

                if ($handle === false) {
                    continue;
                }

                try {
                    while (($entry = readdir($handle)) !== false) {
                        if ($entry === '.' || $entry === '..') {
                            continue;
                        }

                        if (++$visitedEntries > self::MAX_ENTRIES) {
                            throw new RuntimeException('Source discovery exceeds the 100,000 filesystem entry limit. Narrow the analyzed source roots.');
                        }

                        $path = $directory . DIRECTORY_SEPARATOR . $entry;

                        if (is_link($path)) {
                            continue;
                        }

                        $relative = $this->relative($path, $base);

                        if ($relative === null || $this->excluded($relative)) {
                            continue;
                        }

                        if (is_dir($path)) {
                            $stack[] = $path;

                            continue;
                        }

                        if (is_file($path)) {
                            $this->addFile($files, $path, $base, $secrets);
                        }
                    }
                } finally {
                    closedir($handle);
                }
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    private function excluded(string $path): bool
    {
        $normalized = strtolower(trim(str_replace('\\', '/', $path), '/'));
        $segments = explode('/', $normalized);

        if (array_intersect($segments, self::EXCLUDED_SEGMENTS) !== []) {
            return true;
        }

        return $normalized === 'storage'
            || str_starts_with($normalized, 'storage/')
            || $normalized === 'bootstrap/cache'
            || str_starts_with($normalized, 'bootstrap/cache/')
            || $normalized === 'public/build'
            || str_starts_with($normalized, 'public/build/');
    }

    /** @return resource|false */
    private function openDirectory(string $directory)
    {
        // Optional/unreadable roots degrade to absence; warnings must not leak into the long-lived MCP protocol process.
        set_error_handler(static fn(int $severity): bool => $severity === E_WARNING, E_WARNING);

        try {
            return opendir($directory);
        } finally {
            restore_error_handler();
        }
    }

    /** @return list<string> */
    private function packageRoots(InstalledPackage $package, PathPolicy $paths): array
    {
        $candidates = [];
        $count = 0;

        foreach (['psr-4', 'psr-0'] as $key) {
            $mapping = $package->autoload[$key] ?? null;

            if (!is_array($mapping)) {
                continue;
            }

            foreach ($mapping as $value) {
                foreach ((array) $value as $candidate) {
                    if (!is_string($candidate)) {
                        continue;
                    }
                    if (++$count > self::MAX_PACKAGE_AUTOLOAD_PATHS) {
                        throw new RuntimeException(sprintf(
                            'Package source-root discovery exceeds the %d autoload-path limit.',
                            self::MAX_PACKAGE_AUTOLOAD_PATHS,
                        ));
                    }

                    $candidates[] = $candidate;
                }
            }
        }

        foreach (['classmap', 'files'] as $key) {
            $values = $package->autoload[$key] ?? null;

            if (!is_array($values)) {
                continue;
            }

            foreach ($values as $candidate) {
                if (!is_string($candidate)) {
                    continue;
                }
                if (++$count > self::MAX_PACKAGE_AUTOLOAD_PATHS) {
                    throw new RuntimeException(sprintf(
                        'Package source-root discovery exceeds the %d autoload-path limit.',
                        self::MAX_PACKAGE_AUTOLOAD_PATHS,
                    ));
                }

                $candidates[] = $candidate;
            }
        }

        $roots = [];

        foreach (array_values(array_unique($candidates)) as $candidate) {
            try {
                $roots[] = $paths->packagePath($package->name, $candidate);
            } catch (RuntimeException) {
                // Composer autoload entries may be optional or absent in a distribution; only existing approved roots are indexed.
            }
        }

        $roots = array_values(array_unique($roots));
        sort($roots, SORT_STRING);

        return $roots;
    }

    private function relative(string $path, string $base): ?string
    {
        $normalizedPath = str_replace('\\', '/', rtrim($path, '/\\'));
        $normalizedBase = str_replace('\\', '/', rtrim($base, '/\\'));
        $comparisonPath = PHP_OS_FAMILY === 'Windows' ? strtolower($normalizedPath) : $normalizedPath;
        $comparisonBase = PHP_OS_FAMILY === 'Windows' ? strtolower($normalizedBase) : $normalizedBase;

        if ($comparisonPath === $comparisonBase) {
            return '.';
        }

        if (!str_starts_with($comparisonPath, $comparisonBase . '/')) {
            return null;
        }

        return substr($normalizedPath, strlen($normalizedBase) + 1);
    }
}
