<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Security;

use RuntimeException;

final class PathPolicy
{
    private readonly string $projectRoot;

    /** @var array<string, string> */
    private readonly array $packageRoots;

    /**
     * @param array<string, string> $packageRoots
     */
    public function __construct(string $projectRoot, array $packageRoots = [])
    {
        $this->projectRoot = $this->canonicalDirectory($projectRoot, 'project root');

        $resolvedPackages = [];

        foreach ($packageRoots as $package => $path) {
            if (preg_match('~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D', $package) !== 1) {
                throw new RuntimeException('Invalid Composer package name in path policy.');
            }

            $resolvedPackages[$package] = $this->canonicalDirectory($path, 'package root');
        }

        $this->packageRoots = $resolvedPackages;
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * @return array<string, string>
     */
    public function packageRoots(): array
    {
        return $this->packageRoots;
    }

    public function projectPath(string $path): string
    {
        return $this->resolve($this->projectRoot, $path);
    }

    public function projectFile(string $path): string
    {
        return $this->regularFile($this->projectPath($path));
    }

    public function projectDirectory(string $path): string
    {
        return $this->directoryPath($this->projectPath($path));
    }

    public function packagePath(string $package, string $path): string
    {
        $root = $this->packageRoots[$package] ?? null;

        if ($root === null) {
            throw new RuntimeException('Package path is not approved.');
        }

        return $this->resolve($root, $path);
    }

    public function packageFile(string $package, string $path): string
    {
        return $this->regularFile($this->packagePath($package, $path));
    }

    public function packageDirectory(string $package, string $path): string
    {
        return $this->directoryPath($this->packagePath($package, $path));
    }

    private function resolve(string $root, string $path): string
    {
        $relative = $this->relative($path);
        $candidate = $relative === '.'
            ? $root
            : $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $resolved = realpath($candidate);

        if ($resolved === false || !$this->contained($root, $resolved)) {
            throw new RuntimeException('Path is outside the approved root or does not exist.');
        }

        return $resolved;
    }

    private function relative(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('Invalid relative path.');
        }

        $normalized = str_replace('\\', '/', $path);

        if (
            str_starts_with($normalized, '/')
            || str_starts_with($normalized, '//')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
        ) {
            throw new RuntimeException('Absolute paths are not allowed.');
        }

        $segments = [];

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw new RuntimeException('Path traversal is not allowed.');
            }

            $segments[] = $segment;
        }

        return $segments === [] ? '.' : implode('/', $segments);
    }

    private function canonicalDirectory(string $path, string $label): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('Invalid '.$label.'.');
        }

        $resolved = realpath($path);

        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException(ucfirst($label).' does not exist or is not a directory.');
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private function regularFile(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException('Path is not a regular file.');
        }

        return $path;
    }

    private function directoryPath(string $path): string
    {
        if (!is_dir($path)) {
            throw new RuntimeException('Path is not a directory.');
        }

        return $path;
    }

    private function contained(string $root, string $path): bool
    {
        $root = $this->comparisonPath($root);
        $path = $this->comparisonPath($path);

        return $path === $root || str_starts_with($path, $root.'/');
    }

    private function comparisonPath(string $path): string
    {
        $path = str_replace('\\', '/', rtrim($path, '/\\'));

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
