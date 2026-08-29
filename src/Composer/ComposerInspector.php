<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Composer;

use Infocyph\FoundationMcp\Project\Project;

final class ComposerInspector
{
    private const string PACKAGE_PATTERN = '~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D';

    private readonly ComposerMetadataReader $metadata;

    /** @var array<string, InstalledPackage>|null */
    private ?array $packages = null;

    public function __construct(
        private readonly Project $project,
        ?ComposerMetadataReader $metadata = null,
    ) {
        $this->metadata = $metadata ?? new ComposerMetadataReader($project);
    }

    /** @return list<array{code: string, package: ?string, message: string}> */
    public function diagnostics(): array
    {
        $diagnostics = $this->metadata->diagnostics();

        foreach ($this->packages() as $package) {
            $state = $package->state();

            if ($state !== 'matched' && $state !== 'unknown') {
                $diagnostics[] = [
                    'code' => $state,
                    'package' => $package->name,
                    'message' => $this->stateMessage($package, $state),
                ];
            }

            if ($package->installedVersion !== null && $package->installPath === null) {
                $diagnostics[] = [
                    'code' => 'install_path_missing',
                    'package' => $package->name,
                    'message' => 'Installed package has no resolvable canonical install path.',
                ];
            }
        }

        return $diagnostics;
    }

    public function foundation(): ?InstalledPackage
    {
        return $this->package('infocyph/foundation');
    }

    public function graph(): DependencyGraph
    {
        $edges = [];

        foreach ($this->packages() as $package) {
            $requirements = [];

            foreach ($package->require as $name => $constraint) {
                if ($this->isPackageName($name)) {
                    $requirements[$name] = $constraint;
                }
            }

            ksort($requirements, SORT_STRING);
            $edges[$package->name] = $requirements;
        }

        $runtime = [];
        $dev = [];

        foreach ($this->directDependencies() as $name => $item) {
            if ($item['scope'] === 'runtime') {
                $runtime[] = $name;
            } else {
                $dev[] = $name;
            }
        }

        sort($runtime, SORT_STRING);
        sort($dev, SORT_STRING);
        ksort($edges, SORT_STRING);

        return new DependencyGraph($edges, $runtime, $dev);
    }

    public function installedMetadataPresent(): bool
    {
        return $this->metadata->installedMetadataPresent();
    }

    public function lockPresent(): bool
    {
        return $this->metadata->lockPresent();
    }

    public function ownerOfPath(string $path): ?string
    {
        $resolved = realpath($path);

        if ($resolved === false) {
            return null;
        }

        $owners = $this->packageRoots(array_keys($this->packages()));
        uasort($owners, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($owners as $name => $root) {
            if ($this->contained($root, $resolved)) {
                return $name;
            }
        }

        return null;
    }

    public function package(string $name): ?InstalledPackage
    {
        return $this->packages()[$name] ?? null;
    }

    /** @return array<string, string> */
    public function packageRoots(?array $packages = null): array
    {
        $roots = [];
        $selected = $packages === null ? null : array_fill_keys($packages, true);

        foreach ($this->packages() as $package) {
            if ($package->installPath === null) {
                continue;
            }

            if ($selected === null && !str_starts_with($package->name, 'infocyph/')) {
                continue;
            }

            if ($selected !== null && !isset($selected[$package->name])) {
                continue;
            }

            $roots[$package->name] = $package->installPath;
        }

        ksort($roots, SORT_STRING);

        return $roots;
    }

    /** @return array<string, InstalledPackage> */
    public function packages(): array
    {
        if ($this->packages !== null) {
            return $this->packages;
        }

        $locked = $this->metadata->lockedPackages();
        $installed = $this->metadata->installedPackages();
        $direct = $this->directDependencies();
        $names = array_values(array_unique([
            ...array_keys($locked),
            ...array_keys($installed),
            ...array_keys($direct),
        ]));
        sort($names, SORT_STRING);
        $packages = [];

        foreach ($names as $name) {
            $lock = $locked[$name] ?? null;
            $local = $installed[$name] ?? null;
            $declared = $direct[$name] ?? null;
            $installPath = $this->metadata->installPath($name, $local);
            $source = $this->metadata->packageComposer($name, $installPath);

            $packages[$name] = new InstalledPackage(
                name: $name,
                declaredConstraint: $declared['constraint'] ?? null,
                declaredScope: $declared['scope'] ?? null,
                lockedVersion: $this->stringValue($lock['version'] ?? null),
                installedVersion: $this->stringValue($local['version'] ?? null),
                lockedReference: $this->reference($lock),
                installedReference: $this->reference($local),
                installPath: $installPath,
                dev: (bool) ($lock['_dev'] ?? $local['_dev'] ?? false),
                require: $this->stringMap($this->metadataField('require', $lock, $local, $source)),
                autoload: $this->arrayField('autoload', $lock, $local, $source),
                suggest: $this->stringMap($this->metadataField('suggest', $lock, $local, $source)),
                provide: $this->stringMap($this->metadataField('provide', $lock, $local, $source)),
                replace: $this->stringMap($this->metadataField('replace', $lock, $local, $source)),
                conflict: $this->stringMap($this->metadataField('conflict', $lock, $local, $source)),
            );
        }

        return $this->packages = $packages;
    }

    public function phpConstraint(): ?string
    {
        $require = $this->project->composer['require'] ?? [];

        return is_array($require) ? $this->stringValue($require['php'] ?? null) : null;
    }

    /** @return array<string, array{constraint: string, scope: string}> */
    public function platformRequirements(): array
    {
        $platform = [];

        foreach ([['require', 'runtime'], ['require-dev', 'dev']] as [$key, $scope]) {
            $requirements = $this->project->composer[$key] ?? [];

            if (!is_array($requirements)) {
                continue;
            }

            foreach ($requirements as $name => $constraint) {
                if (!is_string($name) || !is_string($constraint) || !$this->isPlatformName($name)) {
                    continue;
                }

                if (!isset($platform[$name]) || $scope === 'runtime') {
                    $platform[$name] = ['constraint' => $constraint, 'scope' => $scope];
                }
            }
        }

        ksort($platform, SORT_STRING);

        return $platform;
    }

    /** @return array<string, mixed> */
    private function arrayField(string $field, ?array $locked, ?array $installed, array $source): array
    {
        $value = $this->metadataField($field, $locked, $installed, $source);

        return is_array($value) ? $value : [];
    }

    private function contained(string $root, string $path): bool
    {
        $root = str_replace('\\', '/', rtrim($root, '/\\'));
        $path = str_replace('\\', '/', rtrim($path, '/\\'));

        if (PHP_OS_FAMILY === 'Windows') {
            $root = strtolower($root);
            $path = strtolower($path);
        }

        return $path === $root || str_starts_with($path, $root . '/');
    }

    /** @return array<string, array{constraint: string, scope: string}> */
    private function directDependencies(): array
    {
        $direct = [];

        foreach ([['require', 'runtime'], ['require-dev', 'dev']] as [$key, $scope]) {
            $requirements = $this->project->composer[$key] ?? [];

            if (!is_array($requirements)) {
                continue;
            }

            foreach ($requirements as $name => $constraint) {
                if (!$this->isPackageName($name) || !is_string($constraint)) {
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

    private function isPackageName(mixed $name): bool
    {
        return is_string($name) && preg_match(self::PACKAGE_PATTERN, $name) === 1;
    }

    private function isPlatformName(string $name): bool
    {
        return $name === 'php'
            || str_starts_with($name, 'ext-')
            || str_starts_with($name, 'lib-')
            || str_starts_with($name, 'composer-');
    }

    private function metadataField(string $field, ?array $locked, ?array $installed, array $source): mixed
    {
        if (is_array($locked) && array_key_exists($field, $locked)) {
            return $locked[$field];
        }

        if (is_array($installed) && array_key_exists($field, $installed)) {
            return $installed[$field];
        }

        return $source[$field] ?? [];
    }

    private function reference(?array $metadata): ?string
    {
        if (!is_array($metadata)) {
            return null;
        }

        foreach (['source', 'dist'] as $key) {
            $source = $metadata[$key] ?? null;
            $reference = is_array($source) ? $source['reference'] ?? null : null;

            if (is_string($reference) && $reference !== '') {
                return $reference;
            }
        }

        return null;
    }

    private function stateMessage(InstalledPackage $package, string $state): string
    {
        return match ($state) {
            'declared_unlocked' => 'Direct dependency is declared but absent from lock and installed metadata.',
            'missing_install' => 'Locked package is not present in installed metadata.',
            'installed_unlocked' => 'Installed package is absent from composer.lock.',
            'version_mismatch' => sprintf(
                'Locked version %s differs from installed version %s.',
                $package->lockedVersion,
                $package->installedVersion,
            ),
            'source_reference_mismatch' => 'Locked and installed source references differ.',
            default => 'Composer package state mismatch.',
        };
    }

    /** @return array<string, string> */
    private function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
