<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Mcp\Resource\ArchitectureResource;
use Infocyph\FoundationMcp\Mcp\Resource\ComposerResource;
use Infocyph\FoundationMcp\Mcp\Resource\ModuleCatalogResource;
use Infocyph\FoundationMcp\Mcp\Resource\PackageFileResource;
use Infocyph\FoundationMcp\Mcp\Resource\ProjectFileResource;
use Infocyph\FoundationMcp\Mcp\Resource\StandardsResource;
use Infocyph\FoundationMcp\Mcp\Resource\SummaryResource;
use Infocyph\FoundationMcp\Mcp\Resource\SymbolResource;
use Infocyph\FoundationMcp\Mcp\ServerFactory;
use Infocyph\FoundationMcp\Mcp\Tool\ToolServices;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;
use Mcp\Server;

it('exposes the complete bounded Foundation resource surface over shared services', function (): void {
    $root = resourceSurfaceProject();
    try {
        $project = (new ProjectDetector())->detect($root);
        $services = new ToolServices($project);

        $summary = json_decode((new SummaryResource($services))->execute(), true, flags: JSON_THROW_ON_ERROR);
        expect($summary['host_type'])->toBe('foundation_custom')
            ->and($summary['foundation']['installed_version'] ?? null)->toBe('2.1.1');

        $architecture = json_decode((new ArchitectureResource($services))->execute(), true, flags: JSON_THROW_ON_ERROR);
        expect($architecture['kind'])->toBe('architecture')
            ->and($architecture['runtime']['runtime'] ?? null)->toBe('web')
            ->and($architecture['modules']['cache']['name'] ?? null)->toBe('cache');

        $composer = json_decode((new ComposerResource($services))->execute(), true, flags: JSON_THROW_ON_ERROR);
        expect($composer['mode'])->toBe('overview')
            ->and($composer['package_count'])->toBeGreaterThanOrEqual(2);

        $catalog = json_decode((new ModuleCatalogResource($services))->execute(), true, flags: JSON_THROW_ON_ERROR);
        expect($catalog['definitions']['cache']['packages']['infocyph/cachelayer'] ?? null)->toBe('^3.2.0')
            ->and($catalog['modules']['cache']['status'])->toContain('packages_installed');

        $standards = json_decode((new StandardsResource($services))->execute(), true, flags: JSON_THROW_ON_ERROR);
        foreach (['project', 'infocyph/foundation', 'infocyph/phpforge'] as $owner) {
            expect(array_any($standards['sources'], static fn (array $source): bool => $source['owner'] === $owner && $source['path'] === 'README.md'))->toBeTrue();
        }

        expect((new ProjectFileResource($services))->execute(rawurlencode('README.md')))->toContain('Project standards')
            ->and((new PackageFileResource($services))->execute(rawurlencode('infocyph/foundation'), rawurlencode('README.md')))->toContain('Foundation standards');

        $symbol = json_decode((new SymbolResource($services))->execute(rawurlencode('App\\Service')), true, flags: JSON_THROW_ON_ERROR);
        expect($symbol['status'])->toBe('resolved')
            ->and($symbol['declaration']['path'] ?? null)->toBe('app/Service.php')
            ->and((new ServerFactory($project))->create())->toBeInstanceOf(Server::class);
    } finally {
        TempProject::remove($root);
    }
});

function resourceSurfaceProject(): string
{
    $locked = [
        ['name' => 'infocyph/foundation', 'version' => '2.1.1', 'source' => ['reference' => 'foundation-ref'], 'require' => ['infocyph/cachelayer' => '^3.2.0']],
        ['name' => 'infocyph/cachelayer', 'version' => '3.2.0', 'source' => ['reference' => 'cache-ref']],
    ];
    $lockedDev = [['name' => 'infocyph/phpforge', 'version' => 'dev-main', 'source' => ['reference' => 'phpforge-ref']]];
    $installed = [
        ['name' => 'infocyph/foundation', 'version' => '2.1.1', 'source' => ['reference' => 'foundation-ref'], 'install-path' => '../infocyph/foundation', 'require' => ['infocyph/cachelayer' => '^3.2.0']],
        ['name' => 'infocyph/cachelayer', 'version' => '3.2.0', 'source' => ['reference' => 'cache-ref'], 'install-path' => '../infocyph/cachelayer'],
        ['name' => 'infocyph/phpforge', 'version' => 'dev-main', 'source' => ['reference' => 'phpforge-ref'], 'install-path' => '../infocyph/phpforge', 'dev' => true],
    ];

    return TempProject::create(
        composer: [
            'require' => ['php' => '^8.4', 'infocyph/foundation' => '^2.1.1', 'infocyph/cachelayer' => '^3.2.0'],
            'require-dev' => ['infocyph/phpforge' => 'dev-main@dev'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ],
        directories: [
            'app', 'bootstrap', 'config', 'routes', 'vendor/composer',
            'vendor/infocyph/foundation/src/Application', 'vendor/infocyph/foundation/src/Module',
            'vendor/infocyph/cachelayer', 'vendor/infocyph/phpforge',
        ],
        files: [
            'README.md' => "# Project standards\nUse strict types and bounded IO.\n",
            'bootstrap/app.php' => "<?php\nuse Infocyph\\Foundation\\Foundation;\nreturn Foundation::web(['base_path' => dirname(__DIR__)]);\n",
            'bootstrap/providers.php' => "<?php return ['common'=>[], 'web'=>[], 'cli'=>[], 'worker'=>[], 'scheduler'=>[]];",
            'composer.lock' => json_encode(['packages' => $locked, 'packages-dev' => $lockedDev], JSON_THROW_ON_ERROR),
            'vendor/composer/installed.json' => json_encode(['packages' => $installed], JSON_THROW_ON_ERROR),
            'vendor/infocyph/foundation/README.md' => "# Foundation standards\nKeep runtime graphs explicit.\n",
            'vendor/infocyph/phpforge/README.md' => "# PHPForge standards\nRun the quality gates.\n",
            'vendor/infocyph/foundation/src/Foundation.php' => <<<'PHP'
<?php
namespace Infocyph\Foundation;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Config\FoundationPreset;
final class Foundation
{
    public static function cli(array $config = []): Application { return self::createFor(RuntimeMode::Cli, $config); }
    public static function scheduler(array $config = []): Application { return self::createFor(RuntimeMode::Scheduler, $config); }
    public static function web(array $config = []): Application { return self::createFor(RuntimeMode::Web, $config); }
    public static function worker(array $config = []): Application { return self::createFor(RuntimeMode::Worker, $config); }
    public static function preset(RuntimeMode $runtime, FoundationPreset $preset, array $config = []): Application { return self::createFor($runtime, $config); }
    private static function createFor(RuntimeMode $runtime, array $config): Application { throw new \RuntimeException('must not execute'); }
}
PHP,
            'vendor/infocyph/foundation/src/Application/ProviderFileLoader.php' => "<?php namespace Infocyph\\Foundation\\Application; final class ProviderFileLoader { private const array GROUPS = ['common','web','cli','worker','scheduler']; }",
            'vendor/infocyph/foundation/src/Module/ModuleCatalog.php' => <<<'PHP'
<?php
namespace Infocyph\Foundation\Module;
final class ModuleCatalog
{
    private const array MODULES = [
        'cache' => [
            'packages' => ['infocyph/cachelayer' => '^3.2.0'],
            'description' => 'Cache stores and locks.',
            'aliases' => ['cachelayer'],
            'config' => ['cache.php'],
            'schemas' => ['cache'],
        ],
    ];
}
PHP,
            'config/cache.php' => '<?php return [];',
            'app/Service.php' => "<?php namespace App; final class Service { public function run(): string { return 'ok'; } }",
        ],
    );
}
