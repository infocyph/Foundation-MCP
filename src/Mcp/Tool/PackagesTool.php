<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Tool;

use Infocyph\FoundationMcp\Composer\InstalledPackage;
use InvalidArgumentException;
use Throwable;

final readonly class PackagesTool
{
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

        if ($package !== '') {
            $installed = $composer->package($package);

            return [
                'mode' => 'package',
                'package' => $installed === null ? null : $this->full($installed),
                'status' => $installed === null ? 'not_found' : 'resolved',
                'dependencies' => array_slice($graph->dependencies($package, $depth), 0, $limit),
                'dependents' => array_slice($graph->dependents($package), 0, $limit),
                'modules' => $this->modulesForPackage($package),
                'diagnostics' => array_slice($composer->diagnostics(), 0, 100),
            ];
        }

        $packages = [];
        foreach ($composer->packages() as $installed) {
            $packages[] = $this->compact($installed);
            if (count($packages) >= $limit) {
                break;
            }
        }

        return [
            'mode' => 'overview',
            'runtime_direct' => $graph->runtimeDirect(),
            'dev_direct' => $graph->devDirect(),
            'package_count' => count($composer->packages()),
            'returned' => count($packages),
            'truncated' => count($composer->packages()) > count($packages),
            'packages' => $packages,
            'modules' => $this->moduleOverview(),
            'diagnostics' => array_slice($composer->diagnostics(), 0, 100),
        ];
    }

    /** @param array<string,string> $map @return array<string,string> */
    private function boundedMap(array $map): array
    {
        ksort($map, SORT_STRING);

        return array_slice($map, 0, 100, true);
    }

    private function boundedValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 5) {
            return is_array($value) ? '[TRUNCATED]' : $value;
        }
        if (is_string($value)) {
            $path = str_replace('\\', '/', $value);
            if (str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/^[A-Za-z]:\//', $path) === 1 || in_array('..', explode('/', $path), true)) {
                return '[DENIED_PATH]';
            }

            return strlen($value) > 2_048 ? substr($value, 0, 2_048) . '…' : $value;
        }
        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if (++$count > 100) {
                $result['__truncated__'] = true;

                break;
            }
            $result[$key] = $this->boundedValue($item, $depth + 1);
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
        return [
            ...$this->compact($package),
            'require' => $this->boundedMap($package->require),
            'autoload' => $this->boundedValue($package->autoload),
            'suggest' => $this->boundedMap($package->suggest),
            'provide' => $this->boundedMap($package->provide),
            'replace' => $this->boundedMap($package->replace),
            'conflict' => $this->boundedMap($package->conflict),
        ];
    }

    /** @return list<array{name:string,packages:list<string>,built_in:bool}> */
    private function moduleOverview(): array
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
        } catch (Throwable) {
            return [];
        }

        return $modules;
    }

    /** @return list<array{name:string,constraint:string}> */
    private function modulesForPackage(string $package): array
    {
        $modules = [];

        try {
            foreach ($this->services->modules()->definitions() as $name => $definition) {
                if (!isset($definition['packages'][$package])) {
                    continue;
                }
                $modules[] = ['name' => $name, 'constraint' => $definition['packages'][$package]];
            }
        } catch (Throwable) {
            return [];
        }
        usort($modules, static fn(array $left, array $right): int => $left['name'] <=> $right['name']);

        return $modules;
    }
}
