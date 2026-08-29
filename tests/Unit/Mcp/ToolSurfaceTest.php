<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Mcp\ServerFactory;
use Infocyph\FoundationMcp\Mcp\Tool\ChangesTool;
use Infocyph\FoundationMcp\Mcp\Tool\ImpactTool;
use Infocyph\FoundationMcp\Mcp\Tool\InspectTool;
use Infocyph\FoundationMcp\Mcp\Tool\PackagesTool;
use Infocyph\FoundationMcp\Mcp\Tool\ProjectTool;
use Infocyph\FoundationMcp\Mcp\Tool\ReadTool;
use Infocyph\FoundationMcp\Mcp\Tool\SearchTool;
use Infocyph\FoundationMcp\Mcp\Tool\SymbolTool;
use Infocyph\FoundationMcp\Mcp\Tool\ToolServices;
use Infocyph\FoundationMcp\Mcp\Tool\UsagesTool;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;
use Mcp\Server;

it('exposes the complete bounded foundation tool surface over shared domain services', function (): void {
    $root = foundationToolProject();

    try {
        $project = (new ProjectDetector())->detect($root);
        $services = new ToolServices($project);

        $summary = (new ProjectTool($services))->execute();
        expect($summary['host_type'])->toBe('foundation_custom')
            ->and($summary['foundation']['installed_version'] ?? null)->toBe('2.1.1')
            ->and($summary['modules']['cache']['name'] ?? null)->toBe('cache')
            ->and($summary['source_roots']['application'])->toContain('app');

        $search = (new SearchTool($services))->execute('App\\Service', kind: 'symbol');
        expect($search['count'])->toBeGreaterThan(0)
            ->and($search['results'][0]['symbol'] ?? null)->toBe('App\\Service');

        $read = (new ReadTool($services))->execute('app/Service.php', start_line: 1, end_line: 20);
        expect($read['scope'])->toBe('project')
            ->and($read['path'])->toBe('app/Service.php')
            ->and($read['content'])->toContain('final class Service');

        $symbol = (new SymbolTool($services))->execute('App\\Service');
        expect($symbol['status'])->toBe('resolved')
            ->and($symbol['declaration']['symbol'] ?? null)->toBe('App\\Service');

        $usages = (new UsagesTool($services))->execute('App\\Service');
        expect($usages['count'])->toBeGreaterThan(0)
            ->and(array_any(
                $usages['usages'],
                static fn (array $usage): bool => $usage['relationship'] === 'new' && $usage['path'] === 'app/Consumer.php',
            ))->toBeTrue();

        $autoload = (new InspectTool($services))->execute('autoload');
        expect($autoload['kind'])->toBe('autoload')
            ->and($autoload['source_roots']['application'])->toContain('app')
            ->and($autoload['source_roots']['tests'])->toContain('tests');

        $packages = (new PackagesTool($services))->execute('infocyph/cachelayer');
        expect($packages['status'])->toBe('resolved')
            ->and($packages['package']['installed_version'] ?? null)->toBe('3.2.0')
            ->and($packages['modules'][0]['name'] ?? null)->toBe('cache');

        $changes = (new ChangesTool($services))->execute();
        expect($changes)->toHaveKeys(['workspace', 'dependencies'])
            ->and($changes['workspace'])->toHaveKeys(['available', 'dirty', 'files', 'diagnostics']);

        $impact = (new ImpactTool($services))->execute('symbol', 'App\\Service');
        expect($impact['kind'])->toBe('symbol')
            ->and($impact['affected_symbols'])->toContain('App\\Service')
            ->and($impact['affected_files'])->toContain('app/Service.php')
            ->and($impact['related_tests'])->toContain('tests/ServiceTest.php');

        expect((new ServerFactory($project))->create())->toBeInstanceOf(Server::class);
    } finally {
        TempProject::remove($root);
    }
});

function foundationToolProject(): string
{
    $locked = [
        [
            'name' => 'infocyph/foundation',
            'version' => '2.1.1',
            'source' => ['reference' => 'foundation-ref'],
            'require' => ['infocyph/cachelayer' => '^3.2.0'],
        ],
        [
            'name' => 'infocyph/cachelayer',
            'version' => '3.2.0',
            'source' => ['reference' => 'cache-ref'],
        ],
    ];
    $installed = [
        [
            'name' => 'infocyph/foundation',
            'version' => '2.1.1',
            'source' => ['reference' => 'foundation-ref'],
            'install-path' => '../infocyph/foundation',
            'require' => ['infocyph/cachelayer' => '^3.2.0'],
        ],
        [
            'name' => 'infocyph/cachelayer',
            'version' => '3.2.0',
            'source' => ['reference' => 'cache-ref'],
            'install-path' => '../infocyph/cachelayer',
        ],
    ];

    return TempProject::create(
        composer: [
            'require' => [
                'php' => '^8.4',
                'infocyph/foundation' => '^2.1.1',
                'infocyph/cachelayer' => '^3.2.0',
            ],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
        ],
        directories: [
            'app',
            'tests',
            'config',
            'vendor/composer',
            'vendor/infocyph/foundation/src/Module',
            'vendor/infocyph/cachelayer',
        ],
        files: [
            'composer.lock' => json_encode(['packages' => $locked, 'packages-dev' => []], JSON_THROW_ON_ERROR),
            'vendor/composer/installed.json' => json_encode(['packages' => $installed], JSON_THROW_ON_ERROR),
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
            'app/Service.php' => <<<'PHP'
<?php
namespace App;
final class Service
{
    public function run(): string { return 'ok'; }
}
PHP,
            'app/Consumer.php' => <<<'PHP'
<?php
namespace App;
final class Consumer
{
    public function make(): Service { return new Service(); }
}
PHP,
            'tests/ServiceTest.php' => <<<'PHP'
<?php
namespace Tests;
use App\Service;
final class ServiceTest
{
    public function testService(): void { new Service(); }
}
PHP,
        ],
    );
}
