<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Project;

use Infocyph\FoundationMcp\Security\PathPolicy;
use RuntimeException;

final readonly class SourceRoots
{
    private const STRUCTURAL_DIRECTORIES = ['bootstrap', 'config', 'routes', 'database', 'docs'];

    /**
     * @param list<string> $application
     * @param list<string> $tests
     * @param list<string> $structural
     */
    public function __construct(
        public array $application,
        public array $tests,
        public array $structural,
    ) {
    }

    public static function discover(Project $project): self
    {
        $paths = new PathPolicy($project->root);
        $application = self::autoloadRoots($project->composer['autoload'] ?? [], $paths);
        $tests = self::autoloadRoots($project->composer['autoload-dev'] ?? [], $paths);

        if (is_dir($project->root.DIRECTORY_SEPARATOR.'tests')) {
            $tests[] = $paths->projectDirectory('tests');
        }

        $structural = [];

        foreach (self::STRUCTURAL_DIRECTORIES as $directory) {
            if (!is_dir($project->root.DIRECTORY_SEPARATOR.$directory)) {
                continue;
            }

            $structural[] = $paths->projectDirectory($directory);
        }

        return new self(
            self::unique($application),
            self::unique($tests),
            self::unique($structural),
        );
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return self::unique([...$this->application, ...$this->tests, ...$this->structural]);
    }

    /**
     * @param mixed $autoload
     *
     * @return list<string>
     */
    private static function autoloadRoots(mixed $autoload, PathPolicy $paths): array
    {
        if (!is_array($autoload)) {
            return [];
        }

        $candidates = [];

        foreach (['psr-4', 'psr-0'] as $key) {
            $mapping = $autoload[$key] ?? [];

            if (!is_array($mapping)) {
                continue;
            }

            foreach ($mapping as $path) {
                foreach ((array) $path as $candidate) {
                    if (is_string($candidate)) {
                        $candidates[] = $candidate;
                    }
                }
            }
        }

        $classmap = $autoload['classmap'] ?? [];

        if (is_array($classmap)) {
            foreach ($classmap as $candidate) {
                if (is_string($candidate)) {
                    $candidates[] = $candidate;
                }
            }
        }

        $roots = [];

        foreach ($candidates as $candidate) {
            try {
                $resolved = $paths->projectPath($candidate);
            } catch (RuntimeException) {
                continue;
            }

            $roots[] = is_dir($resolved) ? $resolved : dirname($resolved);
        }

        return self::unique($roots);
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private static function unique(array $paths): array
    {
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }
}
