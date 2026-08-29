<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Tool;

use Infocyph\FoundationMcp\Composer\InstalledPackage;
use InvalidArgumentException;
use Throwable;

final readonly class PackagesTool
{
    private const int MAX_DIAGNOSTICS = 100;

    private const int MAX_MAP_ITEMS = 100;

    private const int MAX_METADATA_DEPTH = 5;

    private const int MAX_METADATA_ITEMS = 1_000;

    private const int MAX_STRING_BYTES = 2_048;

    public const string DESCRIPTION = 'Inspect bounded Composer package, dependency, exact locked/installed version, source-reference, direct-scope, and Foundation module ownership information.';

    public const array INPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'package' => ['type' => ['string', 'null']],
            'depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'default' => 2],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100],
        ],
        'additionalProperties' => false,
    ];

    public const string NAME = 'foundation_packages';

    public function __construct(
        private ToolServices $services,
    ) {}

    /** @return array<string,mixed> */
    public function execute(?string $package = null, int $depth = 2, int $limit = 100): array
    {
        if ($depth < 1 || $depth > 10) {
            throw new InvalidArgumentException('Package dependency depth must be between 1 and 10.');
        }
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('Package result limit must be between 1 and 200.');
        }

        $composer = $this->services->composer();
        $graph = $composer->graph();
        $package = trim((string) $package);
        $diagnostics = $composer->diagnostics();

        if ($package !== '') {
            $installed = $composer->package($package);
            $dependencies = $graph->dependencies($package, $depth);
            $dependents = $graph->dependents($package);

            return [
                'mode' => 'package',
                'package' => $installed === null ? null : $this->full($installed),
                'status' => $installed === null ? 'not_found' : 'resolved',
                'dependencies' => array_slice($dependencies, 0, $limit),
                'dependencies_truncated' => count($dependencies) > $limit,
                'dependents' => array_slice($dependents, 0, $limit),
                'dependents_truncated' => count($dependents) > $limit,
                'modules' => $this->modulesForPackage($package, $diagnostics),
                'diagnostics' => $this->boundedDiagnostics($diagnostics),
            ];
        }

        $allPackages = $composer->packages();
        $packages = [];
        foreach ($allPackages as $installed) {
            $packages[] = $this->compact($installed);
            if (count($packages) >= $limit) {
                break;
            }
        }

        return [
            'mode' => 'overview',
            'runtime_direct' => $graph->runtimeDirect(),
            'dev_direct' => $graph->devDirect(),
            'package_count' => count($allPackages),
            'returned' => count($packages),
            'truncated' => count($allPackages) > count($packages),
            'packages' => $packages,
            'modules' => $this->moduleOverview($diagnostics),
            'diagnostics' => $this->boundedDiagnostics($diagnostics),
        ];
    }

    /** @param list<array<string,mixed>> $diagnostics @return list<array<string,mixed>> */
    private function boundedDiagnostics(array $diagnostics): array
    {
        if (count($diagnostics) <= self::MAX_DIAGNOSTICS) {
            return $diagnostics;
        }

        return [
            ...array_slice($diagnostics, 0, self::MAX_DIAGNOSTICS - 1),
            [
                'code' => 'diagnostic_limit_exceeded',
                'message' => sprintf('Package diagnostics are limited to %d entries.', self::MAX_DIAGNOSTICS),
            ],
        ];
    }

    /** @param array<string,string> $map @return array{0:array<string,string>,1:bool} */
    private function boundedMap(array $map): array
    {
        ksort($map, SORT_STRING);
        $truncated = count($map) > self::MAX_MAP_ITEMS;

        return [array_slice($map, 0, self::MAX_MAP_ITEMS, true), $truncated];
    }

    /** @return array{0:mixed,1:bool} */
    private function boundedValue(mixed $value): array
    {
        $remaining = self::MAX_METADATA_ITEMS;
        $truncated = false;
        $value = $this->boundedValueRecursive($value, $remaining, $truncated);

        return [$value, $truncated];
    }

    private function boundedValueRecursive(mixed $value, int &$remaining, bool &$truncated, int $depth = 0): mixed
    {
        if ($depth >= self::MAX_METADATA_DEPTH) {
            if (is_array($value)) {
                $truncated = true;

                return '[TRUNCATED]';
            }

            return $value;
        }
        if (is_string($value)) {
            $path = str_replace('\\', '/', $value);
            if (str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/^[A-Za-z]:\//', $path) === 1 || in_array('..', explode('/', $path), true)) {
                return '[DENIED_PATH]';
            }
            if (strlen($value) <= self::MAX_STRING_BYTES) {
                return $value;
            }

            $truncated = true;

            return $this->truncateUtf8($value, self::MAX_STRING_BYTES) . '…';
        }
        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if ($remaining <= 0) {
                $truncated = true;
                $result['__truncated__'] = true;

                break;
            }
            --$remaining;
            $result[$key] = $this->boundedValueRecursive($item, $remaining, $truncated, $depth + 1);
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function compact(InstalledPackage $package): array
    {
        return [
            'name' => $package->name,
            'direct' => $package->direct(),
            'declared_constraint' => $package->declaredConstraint,
            'declared_scope' => $package->declaredScope,
            'locked_version' => $package->lockedVersion,
            'installed_version' => $package->installedVersion,
            'locked_reference' => $package->lockedReference,
            'installed_reference' => $package->installedReference,
            'state' => $package->state(),
            'dev' => $package->dev,
            'source_available' => $package->installPath !== null,
        ];
    }

    /** @return array<string,mixed> */
    private function full(InstalledPackage $package): array
    {
        [$require, $requireTruncated] = $this->boundedMap($package->require);
        [$autoload, $autoloadTruncated] = $this->boundedValue($package->autoload);
        [$suggest, $suggestTruncated] = $this->boundedMap($package->suggest);
        [$provide, $provideTruncated] = $this->boundedMap($package->provide);
        [$replace, $replaceTruncated] = $this->boundedMap($package->replace);
        [$conflict, $conflictTruncated] = $this->boundedMap($package->conflict);

        return [
            ...$this->compact($package),
            'require' => $require,
            'require_truncated' => $requireTruncated,
            'autoload' => $autoload,
            'autoload_truncated' => $autoloadTruncated,
            'suggest' => $suggest,
            'suggest_truncated' => $suggestTruncated,
            'provide' => $provide,
            'provide_truncated' => $provideTruncated,
            'replace' => $replace,
            'replace_truncated' => $replaceTruncated,
            'conflict' => $conflict,
            'conflict_truncated' => $conflictTruncated,
        ];
    }

    /** @param list<array<string,mixed>> $diagnostics @return list<array{name:string,packages:list<string>,built_in:bool}> */
    private function moduleOverview(array &$diagnostics): array
    {
        $modules = [];

        try {
            foreach ($this->services->modules()->definitions() as $name => $definition) {
                $packages = array_keys($definition['packages']);
                sort($packages, SORT_STRING);
                $modules[] = [
                    'name' => $name,
                    'packages' => $packages,
                    'built_in' => $definition['built_in'],
                ];
            }
        } catch (Throwable $error) {
            $diagnostics[] = [
                'code' => 'module_catalog_invalid',
                'message' => $error->getMessage(),
            ];
        }

        return $modules;
    }

    /** @param list<array<string,mixed>> $diagnostics @return list<array{name:string,constraint:string}> */
    private function modulesForPackage(string $package, array &$diagnostics): array
    {
        $modules = [];

        try {
            foreach ($this->services->modules()->definitions() as $name => $definition) {
                if (!isset($definition['packages'][$package])) {
                    continue;
                }
                $modules[] = ['name' => $name, 'constraint' => $definition['packages'][$package]];
            }
        } catch (Throwable $error) {
            $diagnostics[] = [
                'code' => 'module_catalog_invalid',
                'message' => $error->getMessage(),
            ];
        }
        usort($modules, static fn(array $left, array $right): int => $left['name'] <=> $right['name']);

        return $modules;
    }

    private function truncateUtf8(string $value, int $bytes): string
    {
        $value = substr($value, 0, $bytes);
        while ($value !== '' && preg_match('//u', $value) !== 1) {
            $value = substr($value, 0, -1);
        }

        return $value;
    }
}
