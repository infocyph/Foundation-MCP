<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Composer;

use Composer\InstalledVersions;
use Infocyph\FoundationMcp\Project\Project;
use JsonException;
use Throwable;

final class ComposerMetadataReader
{
    private const int JSON_DEPTH = 64;

    private const int MAX_DIAGNOSTICS = 100;

    private const int MAX_METADATA_BYTES = 32 * 1024 * 1024;

    private const int MAX_PACKAGE_CACHE_ENTRIES = 256;

    private const int MAX_PACKAGES = 10_000;

    private const string PACKAGE_PATTERN = '~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D';

    /** @var list<array{code: string, package: ?string, message: string}> */
    private array $diagnostics = [];

    /** @var list<array<string, mixed>>|null */
    private ?array $installed = null;

    private bool $installedLoaded = false;

    /** @var array<string, mixed>|null */
    private ?array $lock = null;

    private bool $lockLoaded = false;

    /** @var array<string, array<string, mixed>> */
    private array $packageComposer = [];

    /** @var list<string> */
    private array $packageComposerOrder = [];

    public function __construct(
        private readonly Project $project,
    ) {}

    /** @return list<array{code: string, package: ?string, message: string}> */
    public function diagnostics(): array
    {
        $this->lockedPackages();
        $this->installedPackages();

        return $this->diagnostics;
    }

    public function installedMetadataPresent(): bool
    {
        if (is_file($this->installedJsonPath())) {
            return true;
        }

        return $this->runtimeMatchesProject();
    }

    /** @return array<string, array<string, mixed>> */
    public function installedPackages(): array
    {
        $items = $this->loadInstalled();

        if ($items === null) {
            return [];
        }

        $packages = [];

        foreach ($items as $item) {
            if (is_array($item) && $this->isPackageName($item['name'] ?? null)) {
                $packages[$item['name']] = $item;
            }
        }

        return $packages;
    }

    public function installPath(string $name, ?array $metadata): ?string
    {
        if (!is_array($metadata)) {
            return null;
        }

        $raw = $metadata['install-path'] ?? $metadata['install_path'] ?? null;

        if (is_string($raw) && $raw !== '') {
            $candidate = $this->absolutePath($raw)
                ? $raw
                : dirname($this->installedJsonPath()) . DIRECTORY_SEPARATOR . $raw;
            $resolved = realpath($candidate);

            if ($resolved !== false && is_dir($resolved)) {
                return $resolved;
            }
        }

        $fallback = $this->project->root
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
        $resolved = realpath($fallback);

        return $resolved !== false && is_dir($resolved) ? $resolved : null;
    }

    /** @return array<string, array<string, mixed>> */
    public function lockedPackages(): array
    {
        $lock = $this->loadLock();

        if ($lock === null) {
            return [];
        }

        $packages = [];

        foreach ([['packages', false], ['packages-dev', true]] as [$key, $dev]) {
            $items = $lock[$key] ?? [];

            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (!is_array($item) || !$this->isPackageName($item['name'] ?? null)) {
                    continue;
                }

                $item['_dev'] = $dev;
                $packages[$item['name']] = $item;
            }
        }

        return $packages;
    }

    public function lockPresent(): bool
    {
        return is_file($this->project->root . DIRECTORY_SEPARATOR . 'composer.lock');
    }

    /** @return array<string, mixed> */
    public function packageComposer(string $name, ?string $installPath): array
    {
        if ($installPath === null) {
            return [];
        }

        if (array_key_exists($installPath, $this->packageComposer)) {
            return $this->packageComposer[$installPath];
        }

        $path = $installPath . DIRECTORY_SEPARATOR . 'composer.json';

        if (!is_file($path)) {
            return $this->rememberPackageComposer($installPath, []);
        }

        try {
            $decoded = json_decode(
                $this->readJsonSource($path, $name . '/composer.json'),
                true,
                self::JSON_DEPTH,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            $this->diagnostic(
                'package_composer_invalid',
                $name,
                'Installed package composer.json cannot be read as valid JSON: ' . $exception->getMessage(),
            );

            return $this->rememberPackageComposer($installPath, []);
        }

        return $this->rememberPackageComposer($installPath, is_array($decoded) ? $decoded : []);
    }

    private function absolutePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return str_starts_with($normalized, '/')
            || str_starts_with($normalized, '//')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1;
    }

    private function diagnostic(string $code, ?string $package, string $message): void
    {
        if (count($this->diagnostics) >= self::MAX_DIAGNOSTICS) {
            return;
        }

        $entry = ['code' => $code, 'package' => $package, 'message' => $message];

        if (!in_array($entry, $this->diagnostics, true)) {
            $this->diagnostics[] = $entry;
        }
    }

    private function installedJsonPath(): string
    {
        return $this->project->root
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'composer'
            . DIRECTORY_SEPARATOR . 'installed.json';
    }

    private function isPackageName(mixed $name): bool
    {
        return is_string($name) && preg_match(self::PACKAGE_PATTERN, $name) === 1;
    }

    /** @return list<array<string, mixed>>|null */
    private function loadInstalled(): ?array
    {
        if ($this->installedLoaded) {
            return $this->installed;
        }

        $this->installedLoaded = true;
        $path = $this->installedJsonPath();

        if (!is_file($path)) {
            return $this->loadInstalledFromRuntime();
        }

        try {
            $decoded = json_decode(
                $this->readJsonSource($path, 'vendor/composer/installed.json'),
                true,
                self::JSON_DEPTH,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            $this->diagnostic(
                'installed_metadata_invalid',
                null,
                'vendor/composer/installed.json is invalid JSON: ' . $exception->getMessage(),
            );

            return null;
        }

        if (!is_array($decoded)) {
            $this->diagnostic(
                'installed_metadata_invalid',
                null,
                'vendor/composer/installed.json did not decode to an object or list.',
            );

            return null;
        }

        $items = is_array($decoded['packages'] ?? null) ? $decoded['packages'] : $decoded;

        if (!array_is_list($items)) {
            $this->diagnostic(
                'installed_metadata_invalid',
                null,
                'vendor/composer/installed.json has an unsupported structure.',
            );

            return null;
        }

        if (count($items) > self::MAX_PACKAGES) {
            $this->diagnostic(
                'installed_metadata_too_large',
                null,
                'vendor/composer/installed.json exceeds the 10,000 package metadata limit.',
            );

            return null;
        }

        $devNames = is_array($decoded['dev-package-names'] ?? null)
            ? array_fill_keys(array_filter($decoded['dev-package-names'], is_string(...)), true)
            : [];
        $packages = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (is_string($item['name'] ?? null) && isset($devNames[$item['name']])) {
                $item['_dev'] = true;
            }

            $packages[] = $item;
        }

        return $this->installed = $packages;
    }

    /** @return list<array<string, mixed>>|null */
    private function loadInstalledFromRuntime(): ?array
    {
        if (!$this->runtimeMatchesProject()) {
            $this->diagnostic(
                'installed_metadata_missing',
                null,
                'Installed Composer metadata is unavailable for the resolved project.',
            );

            return null;
        }

        try {
            $packages = [];

            foreach (InstalledVersions::getInstalledPackages() as $name) {
                if (!$this->isPackageName($name)) {
                    continue;
                }

                if (count($packages) >= self::MAX_PACKAGES) {
                    $this->diagnostic(
                        'installed_metadata_too_large',
                        null,
                        'Composer runtime metadata exceeds the 10,000 package limit.',
                    );

                    return null;
                }

                $packages[] = [
                    'name' => $name,
                    'version' => InstalledVersions::getPrettyVersion($name) ?? InstalledVersions::getVersion($name),
                    'source' => ['reference' => InstalledVersions::getReference($name)],
                    'install_path' => InstalledVersions::getInstallPath($name),
                ];
            }

            return $this->installed = $packages;
        } catch (Throwable) {
            $this->diagnostic(
                'installed_metadata_missing',
                null,
                'Composer runtime metadata could not be read for the resolved project.',
            );

            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function loadLock(): ?array
    {
        if ($this->lockLoaded) {
            return $this->lock;
        }

        $this->lockLoaded = true;
        $path = $this->project->root . DIRECTORY_SEPARATOR . 'composer.lock';

        if (!is_file($path)) {
            $this->diagnostic(
                'composer_lock_missing',
                null,
                'composer.lock is missing; exact locked versions are unavailable.',
            );

            return null;
        }

        try {
            $lock = json_decode(
                $this->readJsonSource($path, 'composer.lock'),
                true,
                self::JSON_DEPTH,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            $this->diagnostic(
                'composer_lock_invalid',
                null,
                'composer.lock is invalid JSON: ' . $exception->getMessage(),
            );

            return null;
        }

        if (!is_array($lock)) {
            $this->diagnostic(
                'composer_lock_invalid',
                null,
                'composer.lock did not decode to an object.',
            );

            return null;
        }

        $packageCount = 0;

        foreach (['packages', 'packages-dev'] as $key) {
            $items = $lock[$key] ?? [];

            if (is_array($items)) {
                $packageCount += count($items);
            }
        }

        if ($packageCount > self::MAX_PACKAGES) {
            $this->diagnostic(
                'composer_lock_too_large',
                null,
                'composer.lock exceeds the 10,000 package metadata limit.',
            );

            return null;
        }

        return $this->lock = $lock;
    }

    /** @param array<string, mixed> $composer @return array<string, mixed> */
    private function rememberPackageComposer(string $installPath, array $composer): array
    {
        if (!array_key_exists($installPath, $this->packageComposer)) {
            if (count($this->packageComposerOrder) >= self::MAX_PACKAGE_CACHE_ENTRIES) {
                $oldest = array_shift($this->packageComposerOrder);

                if (is_string($oldest)) {
                    unset($this->packageComposer[$oldest]);
                }
            }

            $this->packageComposerOrder[] = $installPath;
        }

        $this->packageComposer[$installPath] = $composer;

        return $composer;
    }

    private function readJsonSource(string $path, string $label): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new JsonException('Unable to read ' . $label . '.');
        }

        try {
            $content = stream_get_contents($handle, self::MAX_METADATA_BYTES + 1);
        } finally {
            fclose($handle);
        }

        if (!is_string($content)) {
            throw new JsonException('Unable to read ' . $label . '.');
        }

        if (strlen($content) > self::MAX_METADATA_BYTES) {
            throw new JsonException($label . ' exceeds the 32 MiB metadata limit.');
        }

        return $content;
    }

    private function runtimeMatchesProject(): bool
    {
        try {
            $root = InstalledVersions::getRootPackage();
            $path = $root['install_path'] ?? null;

            if (!is_string($path)) {
                return false;
            }

            $runtimeRoot = realpath($path);
            $projectRoot = realpath($this->project->root);

            return $runtimeRoot !== false && $projectRoot !== false && $runtimeRoot === $projectRoot;
        } catch (Throwable) {
            return false;
        }
    }
}
