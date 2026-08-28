<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Project;

use RuntimeException;

final readonly class ProjectLocator
{
    public function __construct(
        private ProjectDetector $detector = new ProjectDetector(),
    ) {
    }

    public function locate(?string $explicitRoot = null, ?string $cwd = null): string
    {
        if ($explicitRoot !== null) {
            return $this->explicitRoot($explicitRoot);
        }

        $current = $this->directory($cwd ?? getcwd());
        $fallback = null;

        while (true) {
            if (is_file($current.DIRECTORY_SEPARATOR.'composer.json')) {
                $fallback ??= $current;

                if ($this->detector->detect($current)->supported()) {
                    return $current;
                }
            }

            $parent = dirname($current);

            if ($parent === $current) {
                break;
            }

            $current = $parent;
        }

        if ($fallback !== null) {
            return $fallback;
        }

        throw new RuntimeException('Unable to locate a Composer project root.');
    }

    private function explicitRoot(string $root): string
    {
        $resolved = $this->directory($root);

        if (!is_file($resolved.DIRECTORY_SEPARATOR.'composer.json')) {
            throw new RuntimeException('The supplied --root does not contain composer.json.');
        }

        return $resolved;
    }

    private function directory(string|false $path): string
    {
        if (!is_string($path) || $path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('Invalid project root path.');
        }

        $resolved = realpath($path);

        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException('Project root path does not exist or is not a directory.');
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }
}
