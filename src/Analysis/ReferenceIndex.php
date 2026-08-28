<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Analysis;

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\Project;
use InvalidArgumentException;
use Throwable;

/**
 * @phpstan-type IndexedReference array{
 *     scope:string,
 *     package:?string,
 *     path:string,
 *     line:int,
 *     source_symbol:?string,
 *     relationship:string,
 *     target:string,
 *     confidence:string
 * }
 * @phpstan-type ReferenceDiagnostic array{scope:string,package:?string,path:string,code:string,message:string,line:?int}
 * @phpstan-type ReferenceFile array{state:string,references:list<IndexedReference>,diagnostics:list<ReferenceDiagnostic>}
 */
final class ReferenceIndex
{
    private const int MAX_USAGE_RESULTS = 500;

    private readonly PhpAnalyzer $analyzer;
    private readonly SourceFileFinder $files;
    private readonly SymbolIndex $symbols;

    /** @var array<string, ReferenceFile> */
    private array $project = [];

    /** @var array<string, array<string, ReferenceFile>> */
    private array $packages = [];

    public function __construct(
        Project $project,
        ComposerInspector $composer,
        ?PhpAnalyzer $analyzer = null,
        ?SourceFileFinder $files = null,
        ?SymbolIndex $symbols = null,
    ) {
        $this->analyzer = $analyzer ?? new PhpAnalyzer($project, $composer);
        $this->files = $files ?? new SourceFileFinder($project, $composer);
        $this->symbols = $symbols ?? new SymbolIndex($project, $composer, $this->analyzer, $this->files);
    }

    /** @return list<IndexedReference> */
    public function project(): array
    {
        $symbolList = $this->symbols->project();
        $manifest = $this->files->project();
        $this->refresh($this->project, $manifest, null, $symbolList);

        return $this->references($this->project, $symbolList);
    }

    /** @return list<IndexedReference> */
    public function package(string $package): array
    {
        $symbolList = $this->symbols->package($package);
        $manifest = $this->files->package($package);
        $index = $this->packages[$package] ?? [];
        $this->refresh($index, $manifest, $package, $symbolList);
        $this->packages[$package] = $index;

        return $this->references($index, $symbolList);
    }

    /**
     * @param list<string>|null $relationships
     * @return list<IndexedReference>
     */
    public function usages(
        string $symbol,
        ?string $package = null,
        ?array $relationships = null,
        int $limit = 100,
    ): array {
        $needle = ltrim(trim($symbol), '\\');

        if ($needle === '') {
            return [];
        }

        if ($limit < 1 || $limit > self::MAX_USAGE_RESULTS) {
            throw new InvalidArgumentException('Usage result limit must be between 1 and 500.');
        }

        $allowed = $this->relationships($relationships);
        $references = $package === null ? $this->project() : $this->package($package);
        $exact = [];
        $folded = [];
        $lower = strtolower($needle);

        foreach ($references as $reference) {
            if ($allowed !== null && !isset($allowed[$reference['relationship']])) {
                continue;
            }

            if ($reference['target'] === $needle) {
                $exact[] = $reference;
            } elseif (strtolower($reference['target']) === $lower) {
                $folded[] = $reference;
            }

            if (count($exact) >= $limit) {
                break;
            }
        }

        if ($exact !== []) {
            return array_slice($exact, 0, $limit);
        }

        return array_slice($folded, 0, $limit);
    }

    /** @return list<ReferenceDiagnostic> */
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

        usort($diagnostics, static fn (array $left, array $right): int => [
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

    /**
     * @param array<string, ReferenceFile> $index
     * @param array<string, string> $manifest
     * @param list<array<string,mixed>> $symbols
     */
    private function refresh(array &$index, array $manifest, ?string $package, array $symbols): void
    {
        foreach (array_keys($index) as $path) {
            if (!isset($manifest[$path])) {
                unset($index[$path]);
            }
        }

        $symbolsByPath = [];

        foreach ($symbols as $symbol) {
            $symbolsByPath[$symbol['path']][] = $symbol;
        }

        foreach ($manifest as $path => $state) {
            if (($index[$path]['state'] ?? null) === $state) {
                continue;
            }

            $index[$path] = $this->analyze($path, $state, $package, $symbolsByPath[$path] ?? []);
        }
    }

    /**
     * @param list<array<string,mixed>> $fileSymbols
     * @return ReferenceFile
     */
    private function analyze(string $path, string $state, ?string $package, array $fileSymbols): array
    {
        try {
            $result = $package === null
                ? $this->analyzer->project($path)
                : $this->analyzer->package($package, $path);
        } catch (Throwable $exception) {
            return [
                'state' => $state,
                'references' => [],
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

        $references = [];

        foreach ($result->imports as $import) {
            $references[] = [
                'scope' => $result->scope,
                'package' => $result->package,
                'path' => $result->path,
                'line' => $import['line'],
                'source_symbol' => $this->sourceSymbol($import['line'], $fileSymbols),
                'relationship' => 'import',
                'target' => $import['target'],
                'confidence' => 'resolved',
            ];
        }

        foreach ($result->references as $reference) {
            $references[] = [
                'scope' => $result->scope,
                'package' => $result->package,
                'path' => $result->path,
                'line' => $reference['line'],
                'source_symbol' => $this->sourceSymbol($reference['line'], $fileSymbols),
                'relationship' => $reference['relationship'],
                'target' => $reference['target'],
                'confidence' => $reference['confidence'],
            ];
        }

        return [
            'state' => $state,
            'references' => $references,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param array<string, ReferenceFile> $index
     * @param list<array<string,mixed>> $symbols
     * @return list<IndexedReference>
     */
    private function references(array $index, array $symbols): array
    {
        $symbolsByTarget = [];

        foreach ($symbols as $symbol) {
            $symbolsByTarget[strtolower($symbol['symbol'])][] = $symbol['kind'];
        }

        $references = [];

        foreach ($index as $file) {
            foreach ($file['references'] as $reference) {
                if ($reference['confidence'] !== 'dynamic' && $this->proven($reference, $symbolsByTarget)) {
                    $reference['confidence'] = 'exact';
                }

                $references[] = $reference;
            }
        }

        usort($references, static fn (array $left, array $right): int => [
            $left['path'],
            $left['line'],
            $left['relationship'],
            strtolower($left['target']),
            $left['target'],
        ] <=> [
            $right['path'],
            $right['line'],
            $right['relationship'],
            strtolower($right['target']),
            $right['target'],
        ]);

        return $references;
    }

    /**
     * @param IndexedReference $reference
     * @param array<string,list<string>> $symbolsByTarget
     */
    private function proven(array $reference, array $symbolsByTarget): bool
    {
        $kinds = $symbolsByTarget[strtolower($reference['target'])] ?? [];
        $compatible = array_values(array_filter(
            $kinds,
            fn (string $kind): bool => $this->compatibleKind($reference, $kind),
        ));

        return count($compatible) === 1;
    }

    /** @param IndexedReference $reference */
    private function compatibleKind(array $reference, string $kind): bool
    {
        return match ($reference['relationship']) {
            'new', 'extends', 'implements', 'trait-use', 'attribute', 'type' => in_array(
                $kind,
                ['class', 'interface', 'trait', 'enum'],
                true,
            ),
            'class_constant' => in_array($kind, ['class_constant', 'enum_case'], true),
            'property' => $kind === 'property',
            'call' => str_contains($reference['target'], '::')
                ? $kind === 'method'
                : $kind === 'function'
                    && ($reference['confidence'] !== 'lexical' || str_contains($reference['target'], '\\')),
            'import' => in_array($kind, ['class', 'interface', 'trait', 'enum', 'function', 'constant'], true),
            default => false,
        };
    }

    /** @param list<array<string,mixed>> $symbols */
    private function sourceSymbol(int $line, array $symbols): ?string
    {
        $candidates = [];

        foreach ($symbols as $symbol) {
            if ($symbol['line'] <= $line && $symbol['end_line'] >= $line) {
                $candidates[] = $symbol;
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $left, array $right): int => [
            $left['end_line'] - $left['line'],
            -$left['line'],
        ] <=> [
            $right['end_line'] - $right['line'],
            -$right['line'],
        ]);

        return $candidates[0]['symbol'];
    }

    /** @param list<string>|null $relationships @return array<string,true>|null */
    private function relationships(?array $relationships): ?array
    {
        if ($relationships === null) {
            return null;
        }

        $allowed = [];

        foreach ($relationships as $relationship) {
            if (!is_string($relationship) || trim($relationship) === '') {
                throw new InvalidArgumentException('Usage relationships must be non-empty strings.');
            }

            $allowed[$relationship] = true;
        }

        return $allowed;
    }
}
