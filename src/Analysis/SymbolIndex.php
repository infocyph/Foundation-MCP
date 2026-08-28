<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Analysis;

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\Project;
use Throwable;

/**
 * @phpstan-type IndexedSymbol array{
 *     scope:string,
 *     package:?string,
 *     path:string,
 *     kind:string,
 *     name:string,
 *     symbol:string,
 *     line:int,
 *     end_line:int,
 *     visibility:?string,
 *     static:bool,
 *     abstract:bool,
 *     final:bool,
 *     readonly:bool,
 *     type:?string,
 *     parameters:list<array<string,mixed>>,
 *     extends:list<string>,
 *     implements:list<string>,
 *     traits:list<string>,
 *     attributes:list<string>,
 *     doc:?string
 * }
 * @phpstan-type IndexDiagnostic array{scope:string,package:?string,path:string,code:string,message:string,line:?int}
 * @phpstan-type IndexedFile array{state:string,symbols:list<IndexedSymbol>,diagnostics:list<IndexDiagnostic>}
 */
final class SymbolIndex
{
    private readonly PhpAnalyzer $analyzer;

    private readonly SourceFileFinder $files;

    /** @var array<string, array<string, IndexedFile>> */
    private array $packages = [];

    /** @var array<string, IndexedFile> */
    private array $project = [];

    public function __construct(
        Project $project,
        ComposerInspector $composer,
        ?PhpAnalyzer $analyzer = null,
        ?SourceFileFinder $files = null,
    ) {
        $this->analyzer = $analyzer ?? new PhpAnalyzer($project, $composer);
        $this->files = $files ?? new SourceFileFinder($project, $composer);
    }

    /** @return list<IndexDiagnostic> */
    public function diagnostics(?string $package = null): array
    {
        if ($package === null) {
            $this->project();
            $index = $this->project;
        } else {
            $this->package($package);
            $index = $this->packages[$package];
        }

        $diagnostics = [];

        foreach ($index as $file) {
            array_push($diagnostics, ...$file['diagnostics']);
        }

        usort($diagnostics, static fn(array $left, array $right): int => [
            $left['path'],
            $left['line'] ?? 0,
            $left['code'],
        ] <=> [
            $right['path'],
            $right['line'] ?? 0,
            $right['code'],
        ]);

        return $diagnostics;
    }

    /** @return list<IndexedSymbol> */
    public function find(string $symbol, ?string $package = null): array
    {
        $needle = ltrim(trim($symbol), '\\');

        if ($needle === '') {
            return [];
        }

        $symbols = $package === null ? $this->project() : $this->package($package);
        $exact = array_values(array_filter(
            $symbols,
            static fn(array $entry): bool => $entry['symbol'] === $needle,
        ));

        if ($exact !== []) {
            return $exact;
        }

        $folded = strtolower($needle);

        return array_values(array_filter(
            $symbols,
            static fn(array $entry): bool => strtolower($entry['symbol']) === $folded,
        ));
    }

    /** @return list<IndexedSymbol> */
    public function package(string $package): array
    {
        $manifest = $this->files->package($package);
        $index = $this->packages[$package] ?? [];
        $this->refresh($index, $manifest, $package);
        $this->packages[$package] = $index;

        return $this->symbols($index);
    }

    /** @return list<IndexedSymbol> */
    public function project(): array
    {
        $manifest = $this->files->project();
        $this->refresh($this->project, $manifest, null);

        return $this->symbols($this->project);
    }

    /** @return IndexedFile */
    private function analyze(string $path, string $state, ?string $package): array
    {
        try {
            $result = $package === null
                ? $this->analyzer->project($path)
                : $this->analyzer->package($package, $path);
        } catch (Throwable $exception) {
            return [
                'state' => $state,
                'symbols' => [],
                'diagnostics' => [[
                    'scope' => $package === null ? 'project' : 'package',
                    'package' => $package,
                    'path' => $path,
                    'code' => 'internal_analysis_failure',
                    'message' => $exception->getMessage(),
                    'line' => null,
                ]],
            ];
        }

        $diagnostics = [];

        foreach ($result->errors as $error) {
            $diagnostics[] = [
                'scope' => $result->scope,
                'package' => $result->package,
                'path' => $result->path,
                'code' => $error['code'],
                'message' => $error['message'],
                'line' => $error['line'],
            ];
        }

        $symbols = [];

        foreach ($result->declarations as $declaration) {
            $symbols[] = [
                'scope' => $result->scope,
                'package' => $result->package,
                'path' => $result->path,
                ...$declaration,
            ];
        }

        return [
            'state' => $state,
            'symbols' => $symbols,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param array<string, IndexedFile> $index
     * @param array<string, string> $manifest
     */
    private function refresh(array &$index, array $manifest, ?string $package): void
    {
        foreach (array_keys($index) as $path) {
            if (!isset($manifest[$path])) {
                unset($index[$path]);
            }
        }

        foreach ($manifest as $path => $state) {
            if (($index[$path]['state'] ?? null) === $state) {
                continue;
            }

            $index[$path] = $this->analyze($path, $state, $package);
        }
    }

    /**
     * @param array<string, IndexedFile> $index
     * @return list<IndexedSymbol>
     */
    private function symbols(array $index): array
    {
        $symbols = [];

        foreach ($index as $file) {
            array_push($symbols, ...$file['symbols']);
        }

        usort($symbols, static fn(array $left, array $right): int => [
            strtolower($left['symbol']),
            $left['symbol'],
            $left['path'],
            $left['line'],
        ] <=> [
            strtolower($right['symbol']),
            $right['symbol'],
            $right['path'],
            $right['line'],
        ]);

        return $symbols;
    }
}
