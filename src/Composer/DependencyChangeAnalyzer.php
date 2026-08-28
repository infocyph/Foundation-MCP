<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Composer;

use Infocyph\FoundationMcp\Analysis\ReferenceIndex;
use Infocyph\FoundationMcp\Foundation\ModuleCatalogReader;
use Infocyph\FoundationMcp\Git\GitRunner;
use Infocyph\FoundationMcp\Project\Project;
use JsonException;
use RuntimeException;

final class DependencyChangeAnalyzer
{
    private const int MAX_JSON_BYTES = 33_554_432;
    private const int MAX_REFERENCES = 500;
    private const string PACKAGE_PATTERN = '~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D';

    private readonly GitRunner $git;
    private readonly ReferenceIndex $references;

    public function __construct(
        private readonly Project $project,
        private readonly ComposerInspector $composer,
        ?GitRunner $git = null,
        ?ReferenceIndex $references = null,
    ) {
        $this->git = $git ?? new GitRunner($project);
        $this->references = $references ?? new ReferenceIndex($project, $composer);
    }

    /** @return array<string,mixed> */
    public function inspect(): array
    {
        if (!$this->git->available()) {
            return $this->empty([['code' => 'git_unavailable', 'message' => 'Git is unavailable; dependency changes cannot be compared with HEAD.']]);
        }

        $baselineComposer = $this->git->headFile('composer.json');
        if ($baselineComposer === null) {
            return $this->empty([['code' => 'git_head_missing', 'message' => 'HEAD composer.json is unavailable; dependency baseline cannot be established.']]);
        }

        $diagnostics = [];
        try {
            $beforeComposer = $this->decode($baselineComposer, 'HEAD composer.json');
            $beforeLock = $this->decodeOptional($this->git->headFile('composer.lock'), 'HEAD composer.lock');
            $afterLock = $this->readCurrentLock();
        } catch (RuntimeException $error) {
            return $this->empty([['code' => 'composer_change_invalid', 'message' => $error->getMessage()]]);
        }

        $beforeDirect = $this->direct($beforeComposer);
        $afterDirect = $this->direct($this->project->composer);
        $beforeLocked = $this->locked($beforeLock);
        $afterLocked = $this->locked($afterLock);

        $directAdded = [];
        $directRemoved = [];
        $constraintChanges = [];
        $scopeChanges = [];
        foreach ($afterDirect as $name => $entry) {
            if (!isset($beforeDirect[$name])) {
                $directAdded[] = ['package' => $name, ...$entry];
                continue;
            }
            if ($beforeDirect[$name]['constraint'] !== $entry['constraint']) {
                $constraintChanges[] = ['package' => $name, 'before' => $beforeDirect[$name]['constraint'], 'after' => $entry['constraint']];
            }
            if ($beforeDirect[$name]['scope'] !== $entry['scope']) {
                $scopeChanges[] = ['package' => $name, 'before' => $beforeDirect[$name]['scope'], 'after' => $entry['scope']];
            }
        }
        foreach ($beforeDirect as $name => $entry) {
            if (!isset($afterDirect[$name])) {
                $directRemoved[] = ['package' => $name, ...$entry];
            }
        }

        $lockedAdded = [];
        $lockedRemoved = [];
        $versionChanges = [];
        $referenceChanges = [];
        foreach ($afterLocked as $name => $entry) {
            if (!isset($beforeLocked[$name])) {
                $lockedAdded[] = ['package' => $name, 'version' => $entry['version']];
                continue;
            }
            if ($beforeLocked[$name]['version'] !== $entry['version']) {
                $versionChanges[] = ['package' => $name, 'before' => $beforeLocked[$name]['version'], 'after' => $entry['version']];
            }
            if ($beforeLocked[$name]['reference'] !== $entry['reference']) {
                $referenceChanges[] = ['package' => $name, 'before' => $beforeLocked[$name]['reference'], 'after' => $entry['reference']];
            }
        }
        foreach ($beforeLocked as $name => $entry) {
            if (!isset($afterLocked[$name])) {
                $lockedRemoved[] = ['package' => $name, 'version' => $entry['version']];
            }
        }

        $beforeTransitive = array_diff_key($beforeLocked, $beforeDirect);
        $afterTransitive = array_diff_key($afterLocked, $afterDirect);
        $transitiveAdded = array_keys(array_diff_key($afterTransitive, $beforeTransitive));
        $transitiveRemoved = array_keys(array_diff_key($beforeTransitive, $afterTransitive));
        $transitiveChanged = [];
        foreach (array_intersect_key($afterTransitive, $beforeTransitive) as $name => $entry) {
            if ($entry['version'] !== $beforeTransitive[$name]['version'] || $entry['reference'] !== $beforeTransitive[$name]['reference']) {
                $transitiveChanged[] = $name;
            }
        }
        foreach ([$transitiveAdded, $transitiveRemoved, $transitiveChanged] as &$list) {
            sort($list, SORT_STRING);
        }
        unset($list);

        $changedPackages = [];
        foreach ([$directAdded, $directRemoved, $constraintChanges, $scopeChanges, $lockedAdded, $lockedRemoved, $versionChanges, $referenceChanges] as $changes) {
            foreach ($changes as $change) {
                $changedPackages[$change['package']] = true;
            }
        }
        foreach ([$transitiveAdded, $transitiveRemoved, $transitiveChanged] as $list) {
            foreach ($list as $name) {
                $changedPackages[$name] = true;
            }
        }
        ksort($changedPackages, SORT_STRING);
        $changed = array_keys($changedPackages);

        $modules = [];
        try {
            foreach ((new ModuleCatalogReader($this->project, $this->composer))->definitions() as $name => $definition) {
                if (array_intersect(array_keys($definition['packages']), $changed) !== []) {
                    $modules[] = $name;
                }
            }
            sort($modules, SORT_STRING);
        } catch (RuntimeException $error) {
            $diagnostics[] = ['code' => 'module_catalog_unavailable', 'message' => $error->getMessage()];
        }

        $projectReferences = $this->projectReferences($changed, $beforeLocked, $afterLocked);

        return [
            'changed' => $changed !== [],
            'changed_packages' => $changed,
            'direct_added' => $directAdded,
            'direct_removed' => $directRemoved,
            'constraint_changes' => $constraintChanges,
            'scope_changes' => $scopeChanges,
            'locked_added' => $lockedAdded,
            'locked_removed' => $lockedRemoved,
            'version_changes' => $versionChanges,
            'source_reference_changes' => $referenceChanges,
            'transitive' => ['added' => $transitiveAdded, 'removed' => $transitiveRemoved, 'changed' => $transitiveChanged],
            'affected_modules' => $modules,
            'project_references' => $projectReferences,
            'diagnostics' => $diagnostics,
        ];
    }

    /** @return array<string,mixed> */
    private function readCurrentLock(): array
    {
        $path = $this->project->root.DIRECTORY_SEPARATOR.'composer.lock';
        if (!is_file($path)) {
            return [];
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_JSON_BYTES) {
            throw new RuntimeException('composer.lock exceeds the dependency-change inspection limit.');
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read composer.lock.');
        }
        return $this->decode($contents, 'composer.lock');
    }

    /** @return array<string,mixed> */
    private function decodeOptional(?string $json, string $label): array
    {
        return $json === null ? [] : $this->decode($json, $label);
    }

    /** @return array<string,mixed> */
    private function decode(string $json, string $label): array
    {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new RuntimeException($label.' exceeds the dependency-change inspection limit.');
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException($label.' is invalid JSON: '.$error->getMessage(), 0, $error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException($label.' does not contain a JSON object.');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $composer @return array<string,array{constraint:string,scope:string}> */
    private function direct(array $composer): array
    {
        $direct = [];
        foreach ([['require', 'runtime'], ['require-dev', 'dev']] as [$key, $scope]) {
            $requirements = $composer[$key] ?? [];
            if (!is_array($requirements)) {
                continue;
            }
            foreach ($requirements as $name => $constraint) {
                if (!is_string($name) || !is_string($constraint) || preg_match(self::PACKAGE_PATTERN, $name) !== 1) {
                    continue;
                }
                if (!isset($direct[$name]) || $scope === 'runtime') {
                    $direct[$name] = ['constraint' => $constraint, 'scope' => $scope];
                }
            }
        }
        ksort($direct, SORT_STRING);
        return $direct;
    }

    /** @param array<string,mixed> $lock @return array<string,array{version:?string,reference:?string,autoload:array<string,mixed>}> */
    private function locked(array $lock): array
    {
        $packages = [];
        foreach (['packages', 'packages-dev'] as $key) {
            $items = $lock[$key] ?? [];
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item) || !is_string($item['name'] ?? null)) {
                    continue;
                }
                $name = $item['name'];
                $packages[$name] = [
                    'version' => is_string($item['version'] ?? null) ? $item['version'] : null,
                    'reference' => $this->reference($item),
                    'autoload' => is_array($item['autoload'] ?? null) ? $item['autoload'] : [],
                ];
            }
        }
        ksort($packages, SORT_STRING);
        return $packages;
    }

    private function reference(array $item): ?string
    {
        foreach (['source', 'dist'] as $key) {
            $source = $item[$key] ?? null;
            $reference = is_array($source) ? ($source['reference'] ?? null) : null;
            if (is_string($reference) && $reference !== '') {
                return $reference;
            }
        }
        return null;
    }

    /**
     * @param list<string> $changed
     * @param array<string,array{version:?string,reference:?string,autoload:array<string,mixed>}> $before
     * @param array<string,array{version:?string,reference:?string,autoload:array<string,mixed>}> $after
     * @return list<array{package:string,path:string,line:int,target:string,relationship:string,confidence:string}>
     */
    private function projectReferences(array $changed, array $before, array $after): array
    {
        if ($changed === []) {
            return [];
        }
        $prefixes = [];
        foreach ($changed as $package) {
            $values = [];
            $installed = $this->composer->package($package)?->autoload ?? [];
            foreach ([$installed, $before[$package]['autoload'] ?? [], $after[$package]['autoload'] ?? []] as $autoload) {
                $psr4 = is_array($autoload) ? ($autoload['psr-4'] ?? []) : [];
                if (!is_array($psr4)) {
                    continue;
                }
                foreach (array_keys($psr4) as $prefix) {
                    if (is_string($prefix) && $prefix !== '') {
                        $values[$prefix] = true;
                    }
                }
            }
            if ($values !== []) {
                $prefixes[$package] = array_keys($values);
            }
        }

        $results = [];
        foreach ($this->references->project() as $reference) {
            foreach ($prefixes as $package => $packagePrefixes) {
                if (!array_any($packagePrefixes, static fn (string $prefix): bool => str_starts_with(ltrim($reference['target'], '\\'), ltrim($prefix, '\\')))) {
                    continue;
                }
                $results[] = [
                    'package' => $package,
                    'path' => $reference['path'],
                    'line' => $reference['line'],
                    'target' => $reference['target'],
                    'relationship' => $reference['relationship'],
                    'confidence' => $reference['confidence'],
                ];
                if (count($results) >= self::MAX_REFERENCES) {
                    return $results;
                }
                break;
            }
        }
        usort($results, static fn (array $left, array $right): int => [$left['package'], $left['path'], $left['line'], $left['target']] <=> [$right['package'], $right['path'], $right['line'], $right['target']]);
        return $results;
    }

    /** @param list<array{code:string,message:string}> $diagnostics */
    private function empty(array $diagnostics): array
    {
        return [
            'changed' => false,
            'changed_packages' => [],
            'direct_added' => [],
            'direct_removed' => [],
            'constraint_changes' => [],
            'scope_changes' => [],
            'locked_added' => [],
            'locked_removed' => [],
            'version_changes' => [],
            'source_reference_changes' => [],
            'transitive' => ['added' => [], 'removed' => [], 'changed' => []],
            'affected_modules' => [],
            'project_references' => [],
            'diagnostics' => $diagnostics,
        ];
    }
}
