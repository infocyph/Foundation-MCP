<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Analysis;

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Composer\DependencyChangeAnalyzer;
use Infocyph\FoundationMcp\Foundation\ConfigInspector;
use Infocyph\FoundationMcp\Foundation\ModuleCatalogReader;
use Infocyph\FoundationMcp\Foundation\RouteInspector;
use Infocyph\FoundationMcp\Git\WorkspaceInspector;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Security\PathPolicy;
use InvalidArgumentException;
use RuntimeException;

final class ImpactAnalyzer
{
    private const int DEFAULT_LIMIT = 100;

    private const int MAX_LIMIT = 200;

    private readonly DependencyChangeAnalyzer $dependencies;

    private readonly PathPolicy $paths;

    private readonly ReferenceIndex $references;

    private readonly SymbolIndex $symbols;

    private readonly TestLocator $tests;

    private readonly WorkspaceInspector $workspace;

    /** @var array<string,true> */
    private array $affectedSymbols = [];

    /** @var list<array{code:string,message:string}> */
    private array $diagnostics = [];

    /** @var list<array<string,mixed>> */
    private array $evidence = [];

    /** @var array<string,true> */
    private array $evidenceKeys = [];

    /** @var array<string,true> */
    private array $files = [];

    /** @var array<string,true> */
    private array $modules = [];

    /** @var array<string,true> */
    private array $packages = [];

    /** @var array<string,true> */
    private array $relatedTests = [];

    public function __construct(
        private readonly Project $project,
        private readonly ComposerInspector $composer,
        ?SymbolIndex $symbols = null,
        ?ReferenceIndex $references = null,
        ?TestLocator $tests = null,
        ?WorkspaceInspector $workspace = null,
        ?DependencyChangeAnalyzer $dependencies = null,
    ) {
        $this->symbols = $symbols ?? new SymbolIndex($project, $composer);
        $this->references = $references ?? new ReferenceIndex($project, $composer, symbols: $this->symbols);
        $this->tests = $tests ?? new TestLocator($project, $composer, symbols: $this->symbols, references: $this->references);
        $this->workspace = $workspace ?? new WorkspaceInspector($project, $composer, tests: $this->tests);
        $this->dependencies = $dependencies ?? new DependencyChangeAnalyzer($project, $composer, references: $this->references);
        $this->paths = new PathPolicy($project->root);
    }

    /**
     * @return array{kind:string,target:?string,evidence:list<array<string,mixed>>,affected_files:list<string>,affected_symbols:list<string>,affected_packages:list<string>,affected_modules:list<string>,related_tests:list<string>,diagnostics:list<array{code:string,message:string}>}
     */
    public function analyze(string $kind, ?string $target = null, int $limit = self::DEFAULT_LIMIT): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(sprintf('Impact result limit must be between 1 and %d.', self::MAX_LIMIT));
        }
        $kind = strtolower(trim($kind));
        if (!in_array($kind, ['symbol', 'file', 'package', 'module', 'route', 'config', 'changes'], true)) {
            throw new InvalidArgumentException('Unsupported impact kind.');
        }
        if ($kind !== 'changes' && ($target === null || trim($target) === '')) {
            throw new InvalidArgumentException('Impact target is required for this kind.');
        }

        $this->reset();
        match ($kind) {
            'symbol' => $this->symbol((string) $target),
            'file' => $this->file((string) $target),
            'package' => $this->package((string) $target),
            'module' => $this->module((string) $target),
            'route' => $this->route((string) $target),
            'config' => $this->config((string) $target),
            'changes' => $this->changes(),
        };

        $rank = ['exact' => 0, 'resolved' => 1, 'lexical' => 2, 'dynamic' => 3];
        usort($this->evidence, static fn(array $left, array $right): int => [
            $rank[$left['confidence'] ?? 'dynamic'] ?? 4,
            $left['type'] ?? '',
            $left['target'] ?? '',
            $left['path'] ?? '',
            $left['line'] ?? 0,
        ] <=> [
            $rank[$right['confidence'] ?? 'dynamic'] ?? 4,
            $right['type'] ?? '',
            $right['target'] ?? '',
            $right['path'] ?? '',
            $right['line'] ?? 0,
        ]);

        return [
            'kind' => $kind,
            'target' => $kind === 'changes' ? null : $target,
            'evidence' => array_slice($this->evidence, 0, $limit),
            'affected_files' => $this->keys($this->files, $limit),
            'affected_symbols' => $this->keys($this->affectedSymbols, $limit),
            'affected_packages' => $this->keys($this->packages, $limit),
            'affected_modules' => $this->keys($this->modules, $limit),
            'related_tests' => $this->keys($this->relatedTests, $limit),
            'diagnostics' => array_slice($this->diagnostics, 0, 50),
        ];
    }

    private function add(string $type, string $target, string $confidence, string $reason, ?string $path = null, ?int $line = null): void
    {
        $key = implode("\0", [$type, $target, $confidence, $reason, $path ?? '', (string) ($line ?? 0)]);
        if (isset($this->evidenceKeys[$key]) || count($this->evidence) >= self::MAX_LIMIT * 4) {
            return;
        }
        $this->evidenceKeys[$key] = true;
        $this->evidence[] = compact('type', 'target', 'confidence', 'reason', 'path', 'line');
    }

    private function changes(): void
    {
        $workspace = $this->workspace->inspect();
        foreach ($workspace['diagnostics'] as $diagnostic) {
            $this->diagnostics[] = $diagnostic;
        }
        foreach ($workspace['files'] as $file) {
            $this->files[$file['path']] = true;
            $this->add('file', $file['path'], 'exact', 'workspace_' . $file['change'], $file['path']);
        }
        foreach ($workspace['changed_symbols'] as $symbol) {
            $this->affectedSymbols[$symbol] = true;
            $this->add('declaration', $symbol, 'exact', 'workspace_changed_symbol');
            $this->usages($symbol);
        }
        foreach ($workspace['affected_tests'] as $test) {
            $this->relatedTests[$test] = true;
            $this->files[$test] = true;
            $this->add('test', $test, 'resolved', 'workspace_related_test', $test);
        }

        $dependencies = $this->dependencies->inspect();
        foreach ($dependencies['diagnostics'] as $diagnostic) {
            $this->diagnostics[] = $diagnostic;
        }
        foreach ($dependencies['changed_packages'] as $package) {
            $this->packages[$package] = true;
            $this->add('package', $package, 'exact', 'dependency_changed');
        }
        foreach ($dependencies['affected_modules'] as $module) {
            $this->modules[$module] = true;
            $this->add('module', $module, 'exact', 'dependency_change_affects_module');
        }
        foreach ($dependencies['project_references'] as $reference) {
            $this->files[$reference['path']] = true;
            $this->add('reference', $reference['target'], $reference['confidence'], 'dependency_change_project_reference', $reference['path'], $reference['line']);
        }
    }

    private function config(string $target): void
    {
        $result = new ConfigInspector($this->project, $this->composer)->inspect();
        $matched = 0;
        foreach ($result['entries'] as $entry) {
            if ($entry['key'] !== $target && !str_starts_with($entry['key'], rtrim($target, '.') . '.')) {
                continue;
            }
            ++$matched;
            $this->files[$entry['source']] = true;
            $confidence = $entry['status'] === 'dynamic' ? 'dynamic' : 'exact';
            $this->add('config', $entry['key'], $confidence, 'config_key_match', $entry['source'], $entry['line']);
            foreach ($entry['classes'] as $class) {
                $this->affectedSymbols[$class] = true;
                $this->add('reference', $class, 'resolved', 'config_references_class', $entry['source'], $entry['line']);
                $this->usages($class);
            }
        }
        if ($matched === 0) {
            throw new RuntimeException('Config target was not found.');
        }
    }

    private function file(string $path): void
    {
        $resolved = $this->paths->projectFile($path);
        $relative = $this->relative($resolved);
        $this->files[$relative] = true;
        $this->add('file', $relative, 'exact', 'target_file', $relative);
        $found = false;
        foreach ($this->symbols->project() as $symbol) {
            if ($symbol['path'] !== $relative) {
                continue;
            }
            $found = true;
            $this->affectedSymbols[$symbol['symbol']] = true;
            $this->add('declaration', $symbol['symbol'], 'exact', 'declared_in_target_file', $relative, $symbol['line']);
            $this->usages($symbol['symbol']);
        }

        try {
            foreach ($this->tests->forFile($relative, 50) as $test) {
                $this->relatedTests[$test['path']] = true;
                $this->files[$test['path']] = true;
                $this->add('test', $test['path'], $test['confidence'], 'related_test:' . implode(',', $test['reasons']), $test['path']);
            }
        } catch (RuntimeException $error) {
            $this->diagnostics[] = ['code' => 'related_test_unavailable', 'message' => $error->getMessage()];
        }
        if (!$found) {
            $this->diagnostics[] = ['code' => 'file_has_no_indexed_symbols', 'message' => 'Target file contains no indexed PHP declarations; file/test evidence remains available.'];
        }
    }

    /** @param array<string,true> $map @return list<string> */
    private function keys(array $map, int $limit): array
    {
        $keys = array_keys($map);
        sort($keys, SORT_STRING);

        return array_slice($keys, 0, $limit);
    }

    private function module(string $module): void
    {
        $resolved = new ModuleCatalogReader($this->project, $this->composer)->resolve($module);
        if ($resolved === null) {
            throw new RuntimeException('Foundation module was not found.');
        }
        $this->modules[$resolved['name']] = true;
        $this->add('module', $resolved['name'], 'exact', 'target_module');
        foreach (array_keys($resolved['packages']) as $package) {
            $this->packages[$package] = true;
            $this->add('package', $package, 'exact', 'module_package');
            $this->package($package);
        }
        foreach ($resolved['config'] as $config) {
            $path = 'config/' . $config;
            $this->files[$path] = true;
            $this->add('config', $config, is_file($this->project->root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $config) ? 'exact' : 'lexical', 'module_config', $path);
        }
    }

    private function package(string $package): void
    {
        $this->packages[$package] = true;
        $this->add('package', $package, $this->composer->package($package) === null ? 'lexical' : 'exact', 'target_package');
        foreach ($this->composer->graph()->dependents($package) as $dependent) {
            $this->packages[$dependent] = true;
            $this->add('package', $dependent, 'exact', 'depends_on_target_package');
        }

        try {
            foreach (new ModuleCatalogReader($this->project, $this->composer)->definitions() as $name => $definition) {
                if (isset($definition['packages'][$package])) {
                    $this->modules[$name] = true;
                    $this->add('module', $name, 'exact', 'module_owns_target_package');
                }
            }
        } catch (RuntimeException $error) {
            $this->diagnostics[] = ['code' => 'module_catalog_unavailable', 'message' => $error->getMessage()];
        }

        $prefixes = $this->packagePrefixes($package);
        if ($prefixes === []) {
            return;
        }
        foreach ($this->references->project() as $reference) {
            if (!array_any($prefixes, static fn(string $prefix): bool => str_starts_with(ltrim($reference['target'], '\\'), ltrim($prefix, '\\')))) {
                continue;
            }
            $this->files[$reference['path']] = true;
            if ($reference['source_symbol'] !== null) {
                $this->affectedSymbols[$reference['source_symbol']] = true;
            }
            $this->add('reference', $reference['target'], $reference['confidence'], 'project_references_target_package', $reference['path'], $reference['line']);
        }
    }

    /** @return list<string> */
    private function packagePrefixes(string $package): array
    {
        $autoload = $this->composer->package($package)?->autoload ?? [];
        $psr4 = is_array($autoload) ? ($autoload['psr-4'] ?? []) : [];
        if (!is_array($psr4)) {
            return [];
        }
        $prefixes = array_values(array_filter(array_keys($psr4), static fn(mixed $prefix): bool => is_string($prefix) && $prefix !== ''));
        sort($prefixes, SORT_STRING);

        return $prefixes;
    }

    private function relative(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->project->root), '/') . '/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private function reset(): void
    {
        $this->evidence = [];
        $this->evidenceKeys = [];
        $this->files = [];
        $this->affectedSymbols = [];
        $this->packages = [];
        $this->modules = [];
        $this->relatedTests = [];
        $this->diagnostics = [];
    }

    private function route(string $target): void
    {
        $result = new RouteInspector($this->project, $this->composer)->inspect();
        $matched = 0;
        foreach ($result['routes'] as $route) {
            if (!in_array($target, array_filter([$route['name'] ?? null, $route['path'] ?? null, $route['handler'] ?? null], is_string(...)), true)) {
                continue;
            }
            ++$matched;
            $confidence = ($route['status'] ?? '') === 'resolved' ? 'exact' : (($route['status'] ?? '') === 'dynamic' ? 'dynamic' : 'lexical');
            $source = (string) ($route['source'] ?? '');
            if ($source !== '') {
                $this->files[$source] = true;
            }
            $this->add('route', (string) ($route['name'] ?? $route['path'] ?? $target), $confidence, 'route_match', $source !== '' ? $source : null, $route['line'] ?? null);
            $handler = $route['handler'] ?? null;
            if (is_string($handler) && $handler !== '' && count($this->symbols->find($handler)) === 1) {
                $this->symbol($handler);
            }
        }
        if ($matched === 0) {
            throw new RuntimeException('Route target was not found.');
        }
    }

    private function symbol(string $symbol): void
    {
        $matches = $this->symbols->find($symbol);
        if ($matches === []) {
            throw new RuntimeException('Symbol was not found.');
        }
        if (count($matches) > 1) {
            throw new RuntimeException('Symbol is ambiguous.');
        }
        $declaration = $matches[0];
        $this->affectedSymbols[$declaration['symbol']] = true;
        $this->files[$declaration['path']] = true;
        $this->add('declaration', $declaration['symbol'], 'exact', 'target_declaration', $declaration['path'], $declaration['line']);
        $this->usages($declaration['symbol']);
        $this->testsForSymbol($declaration['symbol']);
    }

    private function testsForSymbol(string $symbol): void
    {
        try {
            foreach ($this->tests->forSymbol($symbol, 50) as $test) {
                $this->relatedTests[$test['path']] = true;
                $this->files[$test['path']] = true;
                $this->add('test', $test['path'], $test['confidence'], 'related_test:' . implode(',', $test['reasons']), $test['path']);
            }
        } catch (RuntimeException $error) {
            $this->diagnostics[] = ['code' => 'related_test_unavailable', 'message' => $error->getMessage()];
        }
    }

    private function usages(string $symbol): void
    {
        foreach ($this->references->usages($symbol, limit: 100) as $usage) {
            $this->files[$usage['path']] = true;
            if ($usage['source_symbol'] !== null) {
                $this->affectedSymbols[$usage['source_symbol']] = true;
            }
            $this->add('usage', $usage['target'], $usage['confidence'], $usage['relationship'], $usage['path'], $usage['line']);
        }
    }
}
