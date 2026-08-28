<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Composer\DependencyChangeAnalyzer;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;

it('compares Composer state with HEAD and correlates transitive module and source-reference impact', function (): void {
    $beforeComposer = [
        'require' => ['infocyph/foundation' => '^2.1'],
        'require-dev' => ['infocyph/cachelayer' => '^3.1'],
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ];
    $beforeLock = dependencyLock([
        dependencyPackage('infocyph/foundation', '2.1.1', 'foundation-a'),
        dependencyPackage('infocyph/cachelayer', '3.1.0', 'cache-a', ['Infocyph\\Cache\\' => 'src/']),
        dependencyPackage('psr/log', '3.0.0', 'psr-a'),
    ]);
    $installed = [
        ['name' => 'infocyph/foundation', 'version' => '2.1.1', 'install-path' => '../infocyph/foundation', 'source' => ['reference' => 'foundation-a']],
        ['name' => 'infocyph/cachelayer', 'version' => '3.1.0', 'install-path' => '../infocyph/cachelayer', 'source' => ['reference' => 'cache-a'], 'autoload' => ['psr-4' => ['Infocyph\\Cache\\' => 'src/']]],
    ];

    $root = TempProject::create(
        composer: $beforeComposer,
        directories: ['app', 'vendor/composer', 'vendor/infocyph/foundation/src/Module', 'vendor/infocyph/cachelayer/src'],
        files: [
            'composer.lock' => json_encode($beforeLock, JSON_THROW_ON_ERROR),
            'vendor/composer/installed.json' => json_encode(['packages' => $installed], JSON_THROW_ON_ERROR),
            'vendor/infocyph/foundation/src/Module/ModuleCatalog.php' => dependencyModuleCatalog(),
            'app/UsesCache.php' => <<<'PHP'
<?php
namespace App;
use Infocyph\Cache\CacheClient;
final class UsesCache
{
    public function client(): CacheClient { return new CacheClient(); }
}
PHP,
        ],
    );

    try {
        dependencyGit($root, ['init', '-q']);
        dependencyGit($root, ['config', 'user.email', 'test@example.com']);
        dependencyGit($root, ['config', 'user.name', 'Foundation MCP Test']);
        dependencyGit($root, ['add', '.']);
        dependencyGit($root, ['commit', '-qm', 'baseline']);

        $afterComposer = [
            'require' => [
                'infocyph/foundation' => '^2.1',
                'infocyph/cachelayer' => '^3.2',
                'vendor/new-package' => '^1.0',
            ],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ];
        $afterLock = dependencyLock([
            dependencyPackage('infocyph/foundation', '2.1.1', 'foundation-a'),
            dependencyPackage('infocyph/cachelayer', '3.2.0', 'cache-b', ['Infocyph\\Cache\\' => 'src/']),
            dependencyPackage('vendor/new-package', '1.0.0', 'new-a', ['Vendor\\NewPackage\\' => 'src/']),
            dependencyPackage('psr/log', '3.1.0', 'psr-b'),
        ]);
        file_put_contents($root.'/composer.json', json_encode($afterComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        file_put_contents($root.'/composer.lock', json_encode($afterLock, JSON_THROW_ON_ERROR));

        $project = (new ProjectDetector())->detect($root);
        $result = (new DependencyChangeAnalyzer($project, new ComposerInspector($project)))->inspect();

        expect($result['changed'])->toBeTrue()
            ->and($result['changed_packages'])->toContain('infocyph/cachelayer', 'vendor/new-package', 'psr/log')
            ->and(dependencyChange($result['constraint_changes'], 'infocyph/cachelayer'))->toMatchArray(['before' => '^3.1', 'after' => '^3.2'])
            ->and(dependencyChange($result['scope_changes'], 'infocyph/cachelayer'))->toMatchArray(['before' => 'dev', 'after' => 'runtime'])
            ->and(dependencyChange($result['version_changes'], 'infocyph/cachelayer'))->toMatchArray(['before' => '3.1.0', 'after' => '3.2.0'])
            ->and(dependencyChange($result['source_reference_changes'], 'infocyph/cachelayer'))->toMatchArray(['before' => 'cache-a', 'after' => 'cache-b'])
            ->and($result['transitive']['changed'])->toContain('psr/log')
            ->and($result['affected_modules'])->toContain('cache')
            ->and(array_any($result['project_references'], static fn (array $reference): bool => $reference['package'] === 'infocyph/cachelayer' && $reference['path'] === 'app/UsesCache.php'))->toBeTrue();
    } finally {
        TempProject::remove($root);
    }
});

/** @param list<array<string,mixed>> $changes */
function dependencyChange(array $changes, string $package): ?array
{
    foreach ($changes as $change) {
        if (($change['package'] ?? null) === $package) {
            return $change;
        }
    }
    return null;
}

/** @param list<array<string,mixed>> $packages @return array<string,mixed> */
function dependencyLock(array $packages): array
{
    return ['packages' => $packages, 'packages-dev' => []];
}

/** @param array<string,string> $psr4 @return array<string,mixed> */
function dependencyPackage(string $name, string $version, string $reference, array $psr4 = []): array
{
    $package = ['name' => $name, 'version' => $version, 'source' => ['reference' => $reference]];
    if ($psr4 !== []) {
        $package['autoload'] = ['psr-4' => $psr4];
    }
    return $package;
}

function dependencyModuleCatalog(): string
{
    return <<<'PHP'
<?php
namespace Infocyph\Foundation\Module;
final class ModuleCatalog
{
    private const array MODULES = [
        'cache' => [
            'packages' => ['infocyph/cachelayer' => '^3.2'],
            'built_in' => false,
            'description' => 'Cache support.',
            'aliases' => ['cachelayer'],
            'config' => ['cache.php'],
            'schemas' => [],
        ],
    ];
}
PHP;
}

/** @param list<string> $args */
function dependencyGit(string $root, array $args): string
{
    $process = proc_open(['git', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Git is required for this test.');
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException('Git test command failed: '.$err);
    }
    return $out;
}
