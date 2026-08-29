<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Foundation\CommandInspector;
use Infocyph\FoundationMcp\Foundation\ConfigInspector;
use Infocyph\FoundationMcp\Foundation\FoundationWorkerInspector;
use Infocyph\FoundationMcp\Foundation\OmnibusWorkerInspector;
use Infocyph\FoundationMcp\Foundation\ProviderInspector;
use Infocyph\FoundationMcp\Foundation\ScheduleInspector;
use Infocyph\FoundationMcp\Foundation\WorkerInspector;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;

it('inspects commands providers config schedules and both worker categories without execution', function (): void {
    $root = structuralInspectorsProject();

    try {
        $project = (new ProjectDetector())->detect($root);
        $composer = new ComposerInspector($project);

        $commands = (new CommandInspector($project, $composer))->inspect();
        expect($commands['commands'])->toHaveCount(1)
            ->and($commands['commands'][0]['name'])->toBe('demo')
            ->and($commands['commands'][0]['handler'])->toBe('App\\DemoCommand')
            ->and($commands['commands'][0]['metadata']['description'])->toBe('Demo command')
            ->and($commands['commands'][0]['metadata']['aliases'])->toBe(['d']);

        $providers = (new ProviderInspector($project, $composer))->inspect();
        expect($providers['groups'])->toContain('common', 'web', 'cli', 'worker', 'scheduler')
            ->and($providers['providers'])->toHaveCount(1)
            ->and($providers['providers'][0]['class'])->toBe('App\\AppProvider')
            ->and($providers['providers'][0]['contract'])->toBe('implements')
            ->and($providers['effective']['web'])->toContain('App\\AppProvider');

        $config = (new ConfigInspector($project, $composer))->inspect();
        $effectiveAppName = array_values(array_filter(
            $config['entries'],
            static fn (array $entry): bool => $entry['key'] === 'app.name' && $entry['effective'],
        ));
        expect($effectiveAppName)->toHaveCount(1)
            ->and($effectiveAppName[0]['layer'])->toBe('project')
            ->and($effectiveAppName[0]['value'])->toBe('project');

        $schedule = (new ScheduleInspector($project, $composer))->inspect();
        expect($schedule['route_file'])->toBe('routes/schedule.php')
            ->and($schedule['entries'])->toHaveCount(1)
            ->and($schedule['entries'][0]['command'])->toBe('demo')
            ->and($schedule['entries'][0]['cron'])->toBe('0 * * * *')
            ->and($schedule['entries'][0]['timezone'])->toBe('UTC')
            ->and($schedule['entries'][0]['without_overlap'])->toBeTrue();

        $workers = (new WorkerInspector(
            new FoundationWorkerInspector($project, $composer),
            new OmnibusWorkerInspector($project, $composer),
        ))->inspect();
        expect($workers['foundation_workers']['workers'])->toHaveCount(1)
            ->and($workers['foundation_workers']['workers'][0]['name'])->toBe('cleanup')
            ->and($workers['foundation_workers']['workers'][0]['handler'])->toBe('App\\CleanupWorker')
            ->and($workers['omnibus_workers']['option_fields'])->toContain(
                ['name' => 'concurrency', 'type' => 'int', 'has_default' => true],
                ['name' => 'timeout', 'type' => 'float', 'has_default' => true],
            )
            ->and($workers['omnibus_workers']['workers'])->toHaveCount(1)
            ->and($workers['omnibus_workers']['workers'][0]['name'])->toBe('events')
            ->and($workers['omnibus_workers']['workers'][0]['settings']['concurrency']['value'])->toBe(4)
            ->and($workers['omnibus_workers']['workers'][0]['settings']['timeout']['value'])->toBe(15.0);
    } finally {
        TempProject::remove($root);
    }
});

function structuralInspectorsProject(): string
{
    $locked = [
        [
            'name' => 'infocyph/foundation',
            'version' => '2.1.1',
            'source' => ['reference' => 'foundation-ref'],
        ],
        [
            'name' => 'infocyph/omnibus',
            'version' => '2.1.1',
            'source' => ['reference' => 'omnibus-ref'],
        ],
    ];
    $installed = [
        [
            'name' => 'infocyph/foundation',
            'version' => '2.1.1',
            'source' => ['reference' => 'foundation-ref'],
            'install-path' => '../infocyph/foundation',
            'autoload' => ['psr-4' => ['Infocyph\\Foundation\\' => 'src/']],
        ],
        [
            'name' => 'infocyph/omnibus',
            'version' => '2.1.1',
            'source' => ['reference' => 'omnibus-ref'],
            'install-path' => '../infocyph/omnibus',
            'autoload' => ['psr-4' => ['Infocyph\\Omnibus\\' => 'src/']],
        ],
    ];

    return TempProject::create(
        composer: [
            'name' => 'fixture/structural-host',
            'require' => [
                'php' => '^8.4',
                'infocyph/foundation' => '^2.1.1',
                'infocyph/omnibus' => '^2.1.1',
            ],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ],
        directories: [
            'app',
            'bootstrap',
            'config',
            'routes',
            'vendor/composer',
            'vendor/infocyph/foundation/src/Application',
            'vendor/infocyph/foundation/src/Config',
            'vendor/infocyph/foundation/src/Scheduling',
            'vendor/infocyph/omnibus/src/Consumer',
        ],
        files: [
            'composer.lock' => json_encode(['packages' => $locked, 'packages-dev' => []], JSON_THROW_ON_ERROR),
            'vendor/composer/installed.json' => json_encode(['packages' => $installed], JSON_THROW_ON_ERROR),
            'vendor/infocyph/foundation/src/Application/ProviderFileLoader.php' => <<<'PHP'
<?php
namespace Infocyph\Foundation\Application;
final class ProviderFileLoader
{
    private const array GROUPS = ['common', 'web', 'cli', 'worker', 'scheduler'];
}
PHP,
            'vendor/infocyph/foundation/src/Config/ConfigLoader.php' => <<<'PHP'
<?php
namespace Infocyph\Foundation\Config;
final class ConfigLoader
{
    public function defaults(): array
    {
        return DefaultConfig::all();
    }
}
PHP,
            'vendor/infocyph/foundation/src/Config/DefaultConfig.php' => <<<'PHP'
<?php
namespace Infocyph\Foundation\Config;
final class DefaultConfig
{
    public static function all(): array
    {
        return ['app' => ['name' => 'foundation', 'debug' => false]];
    }
}
PHP,
            'vendor/infocyph/foundation/src/Scheduling/ScheduleManager.php' => <<<'PHP'
<?php
namespace Infocyph\Foundation\Scheduling;
final class ScheduleManager
{
    public function configured(string $file = 'routes/schedule.php'): void {}
}
PHP,
            'vendor/infocyph/foundation/src/Scheduling/ScheduledCommand.php' => <<<'PHP'
<?php
namespace Infocyph\Foundation\Scheduling;
final class ScheduledCommand
{
    public function hourly(): self { return $this; }
    public function timezone(string $timezone): self { return $this; }
    public function withoutOverlap(bool $enabled = true, float $leaseSeconds = 300.0, float $waitSeconds = 0.0): self { return $this; }
}
PHP,
            'vendor/infocyph/omnibus/src/Consumer/WorkerOptions.php' => <<<'PHP'
<?php
namespace Infocyph\Omnibus\Consumer;
final readonly class WorkerOptions
{
    public function __construct(
        public int $concurrency = 1,
        public float $timeout = 30.0,
    ) {}
}
PHP,
            'app/DemoCommand.php' => <<<'PHP'
<?php
namespace App;
final class DemoCommand
{
    public static function define($command): void
    {
        $command->name('demo')->description('Demo command')->alias('d');
    }
}
PHP,
            'app/AppProvider.php' => <<<'PHP'
<?php
namespace App;
final class AppProvider implements \Infocyph\Foundation\Application\ServiceProviderInterface {}
PHP,
            'app/CleanupWorker.php' => <<<'PHP'
<?php
namespace App;
final class CleanupWorker {}
PHP,
            'routes/console.php' => <<<'PHP'
<?php
use App\DemoCommand;
return ['demo' => DemoCommand::class];
PHP,
            'bootstrap/providers.php' => <<<'PHP'
<?php
use App\AppProvider;
return ['common' => [AppProvider::class]];
PHP,
            'config/app.php' => <<<'PHP'
<?php
return ['name' => 'project'];
PHP,
            'config/messaging.php' => <<<'PHP'
<?php
return [
    'workers' => [
        'events' => [
            'concurrency' => 4,
            'timeout' => 15.0,
        ],
    ],
];
PHP,
            'routes/schedule.php' => <<<'PHP'
<?php
return static function ($schedule): void {
    $schedule->command('demo')->hourly()->timezone('UTC')->withoutOverlap();
};
PHP,
            'routes/workers.php' => <<<'PHP'
<?php
use App\CleanupWorker;
return ['cleanup' => CleanupWorker::class];
PHP,
        ],
    );
}
