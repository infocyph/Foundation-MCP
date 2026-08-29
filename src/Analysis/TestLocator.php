<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Analysis;

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Project\SourceRoots;
use Infocyph\FoundationMcp\Security\PathPolicy;
use InvalidArgumentException;
use RuntimeException;

/**
 * @phpstan-type RelatedTest array{
 *     path:string,
 *     score:int,
 *     confidence:string,
 *     reasons:list<string>
 * }
 * @phpstan-type TestCandidate array{score:int,confidence:string,reasons:array<string,true>}
 */
final readonly class TestLocator
{
    private const int DEFAULT_RESULTS = 20;

    private const int MAX_LEXICAL_FILE_BYTES = 262_144;

    private const int MAX_LEXICAL_SCAN_BYTES = 8_388_608;

    private const int MAX_RESULTS = 100;

    private SourceFileFinder $files;

    private PathPolicy $paths;

    private ReferenceIndex $references;

    private SymbolIndex $symbols;

    public function __construct(
        private Project $project,
        ComposerInspector $composer,
        ?SymbolIndex $symbols = null,
        ?ReferenceIndex $references = null,
        ?SourceFileFinder $files = null,
    ) {
        $this->files = $files ?? new SourceFileFinder($project, $composer);
        $this->symbols = $symbols ?? new SymbolIndex($project, $composer, files: $this->files);
        $this->references = $references ?? new ReferenceIndex(
            $project,
            $composer,
            files: $this->files,
            symbols: $this->symbols,
        );
        $this->paths = new PathPolicy($project->root);
    }

    /** @return list<RelatedTest> */
    public function forFile(string $path, int $limit = self::DEFAULT_RESULTS): array
    {
        $this->assertLimit($limit);
        $resolved = $this->paths->projectFile($path);
        $relative = $this->relative($resolved);

        if ($relative === null) {
            throw new RuntimeException('Source file is outside the project root.');
        }

        $targets = array_values(array_filter(
            $this->symbols->project(),
            static fn(array $entry): bool => $entry['path'] === $relative,
        ));

        return $this->locate($targets, [$relative], $limit);
    }

    /** @return list<RelatedTest> */
    public function forSymbol(string $symbol, int $limit = self::DEFAULT_RESULTS): array
    {
        $this->assertLimit($limit);
        $targets = $this->symbols->find($symbol);

        if ($targets === []) {
            return [];
        }

        if (count($targets) > 1) {
            throw new RuntimeException('Symbol is ambiguous; related tests cannot be ranked safely.');
        }

        return $this->locate($targets, [$targets[0]['path']], $limit);
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > self::MAX_RESULTS) {
            throw new InvalidArgumentException('Related-test result limit must be between 1 and 100.');
        }
    }

    private function classLike(string $kind): bool
    {
        return in_array($kind, ['class', 'interface', 'trait', 'enum'], true);
    }

    private function commonDirectorySuffix(string $left, string $right): int
    {
        $left = $this->directorySegments($left);
        $right = $this->directorySegments($right);
        $count = 0;

        while ($left !== [] && $right !== [] && strtolower(end($left)) === strtolower(end($right))) {
            ++$count;
            array_pop($left);
            array_pop($right);
        }

        return $count;
    }

    /** @return list<string> */
    private function directorySegments(string $path): array
    {
        $directory = trim(str_replace('\\', '/', dirname($path)), './');

        if ($directory === '') {
            return [];
        }

        $segments = explode('/', $directory);
        array_shift($segments);

        return array_values(array_filter($segments, static fn(string $segment): bool => $segment !== ''));
    }

    /** @param array<string, TestCandidate> $candidates */
    private function evidence(
        array &$candidates,
        string $path,
        int $score,
        string $confidence,
        string $reason,
    ): void {
        $current = $candidates[$path] ?? [
            'score' => 0,
            'confidence' => 'lexical',
            'reasons' => [],
        ];
        $current['reasons'][$reason] = true;

        if ($score > $current['score']) {
            $current['score'] = $score;
            $current['confidence'] = $confidence;
        }

        $candidates[$path] = $current;
    }

    /**
     * @param array<string, TestCandidate> $candidates
     * @param list<array<string,mixed>> $targets
     * @param list<string> $sourcePaths
     * @param list<string> $testPaths
     */
    private function filenameEvidence(
        array &$candidates,
        array $targets,
        array $sourcePaths,
        array $testPaths,
    ): void {
        $names = [];

        foreach ($targets as $target) {
            $names[strtolower($target['name'])] = true;
        }

        foreach ($sourcePaths as $sourcePath) {
            $names[strtolower(pathinfo($sourcePath, PATHINFO_FILENAME))] = true;
        }

        unset($names['']);

        foreach ($testPaths as $testPath) {
            $testStem = $this->testStem(pathinfo($testPath, PATHINFO_FILENAME));

            foreach (array_keys($names) as $targetStem) {
                if ($testStem === $targetStem) {
                    $this->evidence($candidates, $testPath, 680, 'lexical', 'filename_convention');
                } elseif (str_contains($testStem, $targetStem)) {
                    $this->evidence($candidates, $testPath, 650, 'lexical', 'filename_similarity');
                }
            }
        }
    }

    /** @param list<string> $prefixes */
    private function isTestPath(string $path, array $prefixes): bool
    {
        $path = $this->normalize($path);

        return array_any($prefixes, fn($prefix) => $path === $prefix || str_starts_with($path, $prefix . '/'));
    }

    /**
     * @param array<string, TestCandidate> $candidates
     * @param list<string> $targetSymbols
     * @param list<string> $sourcePaths
     * @param array<string,string> $testFiles
     */
    private function lexicalEvidence(
        array &$candidates,
        array $targetSymbols,
        array $sourcePaths,
        array $testFiles,
    ): void {
        $needles = [];

        foreach ($targetSymbols as $symbol) {
            $needles[$symbol] = true;
            $needles[strtolower($this->shortName($symbol))] = true;
        }

        foreach ($sourcePaths as $path) {
            $needles[strtolower(pathinfo($path, PATHINFO_FILENAME))] = true;
        }

        $needles = array_values(array_filter(
            array_keys($needles),
            static fn(string $needle): bool => strlen($needle) >= 3,
        ));
        $scanned = 0;

        foreach ($testFiles as $path => $absolute) {
            $remaining = self::MAX_LEXICAL_SCAN_BYTES - $scanned;
            if ($remaining <= 0) {
                break;
            }

            $content = $this->readLexicalFile($absolute, min(self::MAX_LEXICAL_FILE_BYTES, $remaining));
            if ($content === null) {
                continue;
            }

            $scanned += strlen($content);
            $lower = strtolower($content);

            foreach ($needles as $needle) {
                if (str_contains($lower, $needle)) {
                    $this->evidence(
                        $candidates,
                        $path,
                        str_contains($needle, '\\') ? 540 : 500,
                        'lexical',
                        'lexical_fallback',
                    );

                    break;
                }
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $targets
     * @param list<string> $sourcePaths
     * @return list<RelatedTest>
     */
    private function locate(array $targets, array $sourcePaths, int $limit): array
    {
        $testFiles = $this->testFiles();

        foreach ($sourcePaths as $sourcePath) {
            unset($testFiles[$sourcePath]);
        }

        if ($testFiles === []) {
            return [];
        }

        /** @var array<string, TestCandidate> $candidates */
        $candidates = [];
        $targetMap = [];

        foreach ($targets as $target) {
            $targetMap[strtolower($target['symbol'])] = $target['kind'];
        }

        $this->referenceEvidence($candidates, $targetMap, $testFiles);
        $this->pathEvidence($candidates, $sourcePaths, array_keys($testFiles));
        $this->filenameEvidence($candidates, $targets, $sourcePaths, array_keys($testFiles));
        $this->lexicalEvidence($candidates, array_keys($targetMap), $sourcePaths, $testFiles);

        $results = [];

        foreach ($candidates as $path => $candidate) {
            $reasons = array_keys($candidate['reasons']);
            sort($reasons, SORT_STRING);
            $results[] = [
                'path' => $path,
                'score' => $candidate['score'],
                'confidence' => $candidate['confidence'],
                'reasons' => $reasons,
            ];
        }

        usort($results, static fn(array $left, array $right): int => [
            -$left['score'],
            $left['path'],
        ] <=> [
            -$right['score'],
            $right['path'],
        ]);

        return array_slice($results, 0, $limit);
    }

    private function normalize(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    /**
     * @param array<string, TestCandidate> $candidates
     * @param list<string> $sourcePaths
     * @param list<string> $testPaths
     */
    private function pathEvidence(array &$candidates, array $sourcePaths, array $testPaths): void
    {
        foreach ($testPaths as $testPath) {
            foreach ($sourcePaths as $sourcePath) {
                $common = $this->commonDirectorySuffix($sourcePath, $testPath);

                if ($common > 0) {
                    $this->evidence(
                        $candidates,
                        $testPath,
                        $common >= 2 ? 760 : 730,
                        'lexical',
                        'path_relationship',
                    );
                }
            }
        }
    }

    private function readLexicalFile(string $path, int $limit): ?string
    {
        if ($limit < 1) {
            return null;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $content = stream_get_contents($handle, $limit + 1);
        } finally {
            fclose($handle);
        }

        if (
            !is_string($content)
            || strlen($content) > $limit
            || str_contains($content, "\0")
            || preg_match('//u', $content) !== 1
        ) {
            return null;
        }

        return $content;
    }

    /**
     * @param array<string, TestCandidate> $candidates
     * @param array<string,string> $targetMap lower symbol => kind
     * @param array<string,string> $testFiles
     */
    private function referenceEvidence(array &$candidates, array $targetMap, array $testFiles): void
    {
        if ($targetMap === []) {
            return;
        }

        foreach ($this->references->project() as $reference) {
            if (!isset($testFiles[$reference['path']])) {
                continue;
            }

            $referenceTarget = strtolower($reference['target']);

            foreach ($targetMap as $target => $kind) {
                $same = $referenceTarget === $target;
                $member = $this->classLike($kind) && str_starts_with($referenceTarget, $target . '::');

                if (!$same && !$member) {
                    continue;
                }

                [$score, $confidence, $reason] = $this->referenceScore($reference, $same);
                $this->evidence($candidates, $reference['path'], $score, $confidence, $reason);
            }
        }
    }

    /** @param array<string,mixed> $reference @return array{int,string,string} */
    private function referenceScore(array $reference, bool $same): array
    {
        if ($same && $reference['confidence'] === 'exact') {
            return [1_000, 'exact', 'exact_symbol_reference'];
        }

        if (in_array($reference['relationship'], ['new', 'call'], true)) {
            return [
                $reference['confidence'] === 'dynamic' ? 820 : 950,
                $reference['confidence'] === 'exact' ? 'exact' : 'resolved',
                $reference['relationship'] === 'new' ? 'direct_construction' : 'direct_call',
            ];
        }

        if ($same && $reference['confidence'] === 'resolved') {
            return [920, 'resolved', 'resolved_symbol_reference'];
        }

        return [580, 'lexical', 'lexical_reference'];
    }

    private function relative(string $path): ?string
    {
        $path = str_replace('\\', '/', rtrim($path, '/\\'));
        $root = str_replace('\\', '/', rtrim($this->project->root, '/\\'));
        $comparisonPath = PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
        $comparisonRoot = PHP_OS_FAMILY === 'Windows' ? strtolower($root) : $root;

        if ($comparisonPath === $comparisonRoot) {
            return '.';
        }

        if (!str_starts_with($comparisonPath, $comparisonRoot . '/')) {
            return null;
        }

        return substr($path, strlen($root) + 1);
    }

    private function shortName(string $symbol): string
    {
        if (str_contains($symbol, '::')) {
            return substr($symbol, strrpos($symbol, '::') + 2);
        }

        $position = strrpos($symbol, '\\');

        return $position === false ? $symbol : substr($symbol, $position + 1);
    }

    /** @return array<string,string> relative path => absolute path */
    private function testFiles(): array
    {
        $prefixes = $this->testPrefixes();
        $files = [];

        foreach (array_keys($this->files->project()) as $path) {
            if (!$this->isTestPath($path, $prefixes)) {
                continue;
            }

            try {
                $files[$path] = $this->paths->projectFile($path);
            } catch (RuntimeException) {
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /** @return list<string> */
    private function testPrefixes(): array
    {
        $prefixes = [];

        foreach (SourceRoots::discover($this->project)->tests as $root) {
            $relative = $this->relative($root);

            if ($relative !== null && $relative !== '.') {
                $prefixes[] = trim($relative, '/');
            }
        }

        foreach (['tests', 'test', 'spec'] as $directory) {
            if (is_dir($this->project->root . DIRECTORY_SEPARATOR . $directory)) {
                $prefixes[] = $directory;
            }
        }

        $prefixes = array_values(array_unique($prefixes));
        sort($prefixes, SORT_STRING);

        return $prefixes;
    }

    private function testStem(string $name): string
    {
        $name = strtolower($name);

        foreach (['test', 'tests', 'spec'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return substr($name, 0, -strlen($suffix));
            }
        }

        return $name;
    }
}
