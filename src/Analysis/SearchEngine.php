<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Analysis;

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Security\Redactor;
use Infocyph\FoundationMcp\Security\SecretPolicy;
use InvalidArgumentException;
use RuntimeException;

/**
 * @phpstan-type SearchResult array{
 *     kind:string,
 *     scope:string,
 *     package:?string,
 *     path:string,
 *     line:?int,
 *     score:int,
 *     symbol:?string,
 *     excerpt:?string
 * }
 * @phpstan-type SearchTarget array{scope:string,package:?string,root:string,prefix:?string}
 */
final readonly class SearchEngine
{
    private const int DEFAULT_RESULTS = 20;

    private const array KINDS = ['auto', 'symbol', 'path', 'text'];

    private const int MAX_EXCERPT_BYTES = 240;

    private const int MAX_RESULTS = 100;

    private const int MAX_SCAN_FILES = 2_500;

    private const int MAX_TEXT_FILE_BYTES = 524_288;

    private const int MAX_TEXT_SCAN_BYTES = 16_777_216;

    private const array SCOPES = [
        'project',
        'tests',
        'foundation',
        'packages',
        'routes',
        'config',
        'bootstrap',
        'docs',
        'all',
    ];

    private const array TEXT_EXTENSIONS = [
        'conf',
        'dist',
        'ini',
        'json',
        'md',
        'neon',
        'php',
        'stub',
        'txt',
        'xml',
        'yaml',
        'yml',
    ];

    private Redactor $redactor;

    private SecretPolicy $secrets;

    private SymbolIndex $symbols;

    public function __construct(
        private Project $project,
        private ComposerInspector $composer,
        ?SymbolIndex $symbols = null,
        ?SecretPolicy $secrets = null,
        ?Redactor $redactor = null,
    ) {
        $this->symbols = $symbols ?? new SymbolIndex($project, $composer);
        $this->secrets = $secrets ?? new SecretPolicy();
        $this->redactor = $redactor ?? new Redactor();
    }

    /** @return list<SearchResult> */
    public function search(
        string $query,
        string $scope = 'project',
        string $kind = 'auto',
        ?string $package = null,
        int $limit = self::DEFAULT_RESULTS,
    ): array {
        $query = trim($query);

        if ($query === '') {
            throw new InvalidArgumentException('Search query must not be empty.');
        }

        if (!in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('Unsupported search scope.');
        }

        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unsupported search kind.');
        }

        if ($limit < 1 || $limit > self::MAX_RESULTS) {
            throw new InvalidArgumentException('Search result limit must be between 1 and 100.');
        }

        $targets = $this->targets($scope, $package);
        $results = [];

        if ($kind === 'auto' || $kind === 'symbol') {
            array_push($results, ...$this->symbolResults($query, $targets));
        }

        if ($kind === 'auto' || $kind === 'path' || $kind === 'text') {
            foreach ($targets as $target) {
                $manifest = $this->resourceManifest($target);

                if ($kind === 'auto' || $kind === 'path') {
                    array_push($results, ...$this->pathResults($query, $target, $manifest));
                }

                if ($kind === 'auto' || $kind === 'text') {
                    array_push($results, ...$this->textResults($query, $target, $manifest));
                }
            }
        }

        usort($results, static fn(array $left, array $right): int => [
            -$left['score'],
            $left['kind'],
            $left['scope'],
            $left['package'] ?? '',
            $left['path'],
            $left['line'] ?? 0,
            $left['symbol'] ?? '',
        ] <=> [
            -$right['score'],
            $right['kind'],
            $right['scope'],
            $right['package'] ?? '',
            $right['path'],
            $right['line'] ?? 0,
            $right['symbol'] ?? '',
        ]);

        return array_slice($results, 0, $limit);
    }

    /** @return list<SearchTarget> */
    private function allTargets(?string $package): array
    {
        $targets = [$this->projectTarget('project', null)];

        if ($this->composer->package('infocyph/foundation')?->installPath !== null) {
            $targets[] = $this->packageTarget('foundation', 'infocyph/foundation');
        }

        if ($package !== null && $package !== '' && $package !== 'infocyph/foundation') {
            $targets[] = $this->packageTarget('packages', $package);
        }

        return $targets;
    }

    private function couldContainPrefix(string $path, ?string $prefix): bool
    {
        if ($prefix === null) {
            return true;
        }

        $path = trim(str_replace('\\', '/', $path), '/');

        return $path === $prefix
            || str_starts_with($prefix, $path . '/')
            || str_starts_with($path, $prefix . '/');
    }

    private function excerpt(string $value): string
    {
        if (strlen($value) <= self::MAX_EXCERPT_BYTES) {
            return $value;
        }

        return substr($value, 0, self::MAX_EXCERPT_BYTES - 3) . '...';
    }

    private function excluded(string $path, bool $project): bool
    {
        $normalized = strtolower(trim(str_replace('\\', '/', $path), '/'));
        $segments = explode('/', $normalized);

        foreach (['.git', 'build', 'coverage', 'dist', 'node_modules', 'storage', 'temp', 'tmp', 'vendor'] as $segment) {
            if (in_array($segment, $segments, true)) {
                return true;
            }
        }

        if (
            $normalized === 'bootstrap/cache'
            || str_starts_with($normalized, 'bootstrap/cache/')
            || $normalized === 'public/build'
            || str_starts_with($normalized, 'public/build/')
        ) {
            return true;
        }

        return $project && ($normalized === 'vendor' || str_starts_with($normalized, 'vendor/'));
    }

    /** @return SearchTarget */
    private function packageTarget(string $scope, string $package): array
    {
        $installed = $this->composer->package($package);

        if ($installed?->installPath === null) {
            throw new RuntimeException('Requested package is not installed with a source root.');
        }

        return [
            'scope' => $scope,
            'package' => $package,
            'root' => $installed->installPath,
            'prefix' => null,
        ];
    }

    /** @param SearchTarget $target */
    private function pathInTarget(string $path, array $target): bool
    {
        $prefix = $target['prefix'];

        if ($prefix === null) {
            return true;
        }

        $normalized = trim(str_replace('\\', '/', $path), '/');

        return $normalized === $prefix || str_starts_with($normalized, $prefix . '/');
    }

    /**
     * @param SearchTarget $target
     * @param array<string,string> $manifest path => absolute path
     * @return list<SearchResult>
     */
    private function pathResults(string $query, array $target, array $manifest): array
    {
        $results = [];
        $needle = strtolower(trim(str_replace('\\', '/', $query), '/'));

        foreach ($manifest as $path => $_absolute) {
            $normalized = strtolower($path);
            $basename = strtolower(basename($path));
            $score = match (true) {
                $normalized === $needle => 780,
                $basename === $needle => 760,
                str_starts_with($normalized, $needle) => 710,
                str_starts_with($basename, $needle) => 700,
                str_contains($basename, $needle) => 680,
                str_contains($normalized, $needle) => 660,
                default => null,
            };

            if ($score === null) {
                continue;
            }

            $results[] = [
                'kind' => 'path',
                'scope' => $target['scope'],
                'package' => $target['package'],
                'path' => $path,
                'line' => null,
                'score' => $score,
                'symbol' => null,
                'excerpt' => null,
            ];
        }

        return $results;
    }

    /** @return SearchTarget */
    private function projectTarget(string $scope, ?string $prefix): array
    {
        return [
            'scope' => $scope,
            'package' => null,
            'root' => $this->project->root,
            'prefix' => $prefix,
        ];
    }

    private function relative(string $path, string $root): ?string
    {
        $path = str_replace('\\', '/', rtrim($path, '/\\'));
        $root = str_replace('\\', '/', rtrim($root, '/\\'));
        $comparisonPath = PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
        $comparisonRoot = PHP_OS_FAMILY === 'Windows' ? strtolower($root) : $root;

        if (!str_starts_with($comparisonPath, $comparisonRoot . '/')) {
            return null;
        }

        return substr($path, strlen($root) + 1);
    }

    private function requiredPackage(?string $package): string
    {
        $package = trim((string) $package);

        if ($package === '') {
            throw new InvalidArgumentException('Package scope requires an explicit package name.');
        }

        return $package;
    }

    /** @param SearchTarget $target @return array<string,string> path => absolute path */
    private function resourceManifest(array $target): array
    {
        $files = [];
        $stack = [$target['root']];

        while (($directory = array_pop($stack)) !== null) {
            $entries = scandir($directory);

            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $absolute = $directory . DIRECTORY_SEPARATOR . $entry;

                if (is_link($absolute)) {
                    continue;
                }

                $path = $this->relative($absolute, $target['root']);

                if ($path === null || $this->excluded($path, $target['package'] === null)) {
                    continue;
                }

                if (!$this->pathInTarget($path, $target)) {
                    if (is_dir($absolute) && $this->couldContainPrefix($path, $target['prefix'])) {
                        $stack[] = $absolute;
                    }

                    continue;
                }

                if (is_dir($absolute)) {
                    $stack[] = $absolute;

                    continue;
                }

                if (!is_file($absolute) || $this->secrets->denied($path)) {
                    continue;
                }

                $files[$path] = $absolute;

                if (count($files) >= self::MAX_SCAN_FILES) {
                    break 2;
                }
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /** @param array<string,mixed> $symbol */
    private function symbolExcerpt(array $symbol): string
    {
        $signature = $symbol['kind'] . ' ' . $symbol['symbol'];

        if ($symbol['type'] !== null) {
            $signature .= ': ' . $symbol['type'];
        }

        return $this->excerpt($signature);
    }

    /** @param list<SearchTarget> $targets @return list<SearchResult> */
    private function symbolResults(string $query, array $targets): array
    {
        $results = [];
        $needle = ltrim($query, '\\');
        $lower = strtolower($needle);

        foreach ($targets as $target) {
            $symbols = $target['package'] === null
                ? $this->symbols->project()
                : $this->symbols->package($target['package']);

            foreach ($symbols as $symbol) {
                if (!$this->pathInTarget($symbol['path'], $target)) {
                    continue;
                }

                $score = $this->symbolScore($symbol, $needle, $lower);

                if ($score === null) {
                    continue;
                }

                $results[] = [
                    'kind' => 'symbol',
                    'scope' => $target['scope'],
                    'package' => $target['package'],
                    'path' => $symbol['path'],
                    'line' => $symbol['line'],
                    'score' => $score,
                    'symbol' => $symbol['symbol'],
                    'excerpt' => $this->symbolExcerpt($symbol),
                ];
            }
        }

        return $results;
    }

    /** @param array<string,mixed> $symbol */
    private function symbolScore(array $symbol, string $needle, string $lower): ?int
    {
        if ($symbol['symbol'] === $needle) {
            return 1_000;
        }

        $symbolLower = strtolower($symbol['symbol']);

        if ($symbolLower === $lower) {
            return 990;
        }

        if ($symbol['name'] === $needle) {
            return 950;
        }

        $nameLower = strtolower($symbol['name']);

        if ($nameLower === $lower) {
            return 940;
        }

        if (str_starts_with($symbolLower, $lower)) {
            return 880;
        }

        if (str_starts_with($nameLower, $lower)) {
            return 870;
        }

        if (str_contains($symbolLower, $lower)) {
            return 820;
        }

        return str_contains($nameLower, $lower) ? 810 : null;
    }

    /** @return list<SearchTarget> */
    private function targets(string $scope, ?string $package): array
    {
        return match ($scope) {
            'project' => [$this->projectTarget('project', null)],
            'tests', 'routes', 'config', 'bootstrap', 'docs' => [$this->projectTarget($scope, $scope)],
            'foundation' => [$this->packageTarget('foundation', 'infocyph/foundation')],
            'packages' => [$this->packageTarget('packages', $this->requiredPackage($package))],
            'all' => $this->allTargets($package),
            default => throw new InvalidArgumentException('Unsupported search scope.'),
        };
    }

    private function textCandidate(string $path): bool
    {
        $basename = strtolower(basename($path));

        if ($basename === '.env.example') {
            return true;
        }

        return in_array(strtolower(pathinfo($basename, PATHINFO_EXTENSION)), self::TEXT_EXTENSIONS, true);
    }

    /**
     * @param SearchTarget $target
     * @param array<string,string> $manifest path => absolute path
     * @return list<SearchResult>
     */
    private function textResults(string $query, array $target, array $manifest): array
    {
        $results = [];
        $scanned = 0;
        $lower = strtolower($query);

        foreach ($manifest as $path => $absolute) {
            if (!$this->textCandidate($path)) {
                continue;
            }

            $size = filesize($absolute);

            if ($size === false || $size > self::MAX_TEXT_FILE_BYTES) {
                continue;
            }

            if (($scanned + $size) > self::MAX_TEXT_SCAN_BYTES) {
                break;
            }

            $scanned += $size;
            $content = file_get_contents($absolute);

            if (
                $content === false
                || strlen($content) > self::MAX_TEXT_FILE_BYTES
                || str_contains($content, "\0")
                || preg_match('//u', $content) !== 1
            ) {
                continue;
            }

            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));

            foreach ($lines as $index => $line) {
                $position = stripos($line, $query);

                if ($position === false) {
                    continue;
                }

                $trimmed = trim($line);
                $trimmedLower = strtolower($trimmed);
                $score = match (true) {
                    $trimmedLower === $lower => 620,
                    str_starts_with($trimmedLower, $lower) => 600,
                    default => 560,
                };

                $results[] = [
                    'kind' => 'text',
                    'scope' => $target['scope'],
                    'package' => $target['package'],
                    'path' => $path,
                    'line' => $index + 1,
                    'score' => $score,
                    'symbol' => null,
                    'excerpt' => $this->excerpt($this->redactor->redact($trimmed)),
                ];

                if (count($results) >= self::MAX_RESULTS) {
                    return $results;
                }
            }
        }

        return $results;
    }
}
