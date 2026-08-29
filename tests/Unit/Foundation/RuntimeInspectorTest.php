<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Foundation\RuntimeInspector;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;

it('inspects the selected Foundation runtime graph and bootstrap options without execution', function (): void {
    $root = runtimeInspectorProject(<<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;

return Foundation::web([
    'base_path' => dirname(__DIR__),
    'debug' => true,
    'security' => [
        'token' => 'super-secret-value',
    ],
]);
PHP);

    try {
        $project = (new ProjectDetector())->detect($root);
        $result = (new RuntimeInspector($project, new ComposerInspector($project)))->inspect();

        expect($result['runtime'])->toBe('web')
            ->and($result['runtime_method'])->toBe('web')
            ->and($result['runtime_status'])->toBe('resolved')
            ->and($result['runtime_graphs']['web'])->toBe(['selected' => true, 'available' => true])
            ->and($result['runtime_graphs']['cli'])->toBe(['selected' => false, 'available' => true])
            ->and($result['runtime_graphs']['worker'])->toBe(['selected' => false, 'available' => true])
            ->and($result['runtime_graphs']['scheduler'])->toBe(['selected' => false, 'available' => true])
            ->and($result['base_path'])->toBe('project_root')
            ->and($result['base_path_status'])->toBe('project_root')
            ->and(runtimeOption($result['inline_options'], 'app.debug')['value'] ?? null)->toBeTrue()
            ->and(runtimeOption($result['inline_options'], 'security.token')['value'] ?? null)->toBe('[REDACTED]');
    } finally {
        TempProject::remove($root);
    }
});

it('inspects preset runtime selection and preserves dynamic bootstrap state', function (): void {
    $root = runtimeInspectorProject(<<<'PHP'
<?php

declare(strict_types=1);

use App\Preset\ProductionPreset;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Foundation;

return Foundation::preset(
    RuntimeMode::Worker,
    new ProductionPreset(),
    ['base_path' => $basePath, 'workers' => ['sleep' => 1]],
);
PHP);

    try {
        $project = (new ProjectDetector())->detect($root);
        $result = (new RuntimeInspector($project, new ComposerInspector($project)))->inspect();

        expect($result['runtime'])->toBe('worker')
            ->and($result['runtime_method'])->toBe('preset')
            ->and($result['selected_preset'])->toBe('App\\Preset\\ProductionPreset')
            ->and($result['runtime_graphs']['worker']['selected'])->toBeTrue()
            ->and($result['base_path'])->toBeNull()
            ->and($result['base_path_status'])->toBe('dynamic');
    } finally {
        TempProject::remove($root);
    }
});

it('refuses to execute a dynamic installed runtime contract', function (): void {
    $root = runtimeInspectorProject("<?php return \\Infocyph\\Foundation\\Foundation::web([]);");

    try {
        file_put_contents(
            $root.'/vendor/infocyph/foundation/src/Foundation.php',
            <<<'PHP'
<?php
namespace Infocyph\Foundation;
final class Foundation
{
    public static function web(array $config = []): object
    {
        throw new \RuntimeException('must not execute');
    }
}
PHP,
        );

        $project = (new ProjectDetector())->detect($root);
        $result = (new RuntimeInspector($project, new ComposerInspector($project)))->inspect();

        expect($result['runtime'])->toBeNull()
            ->and($result['runtime_status'])->toBe('dynamic')
            ->and(array_any(
                $result['diagnostics'],
                static fn (array $item): bool => $item['code'] === 'runtime_dynamic',
            ))->toBeTrue();
    } finally {
        TempProject::remove($root);
    }
});

/** @param list<array<string,mixed>> $options */
function runtimeOption(array $options, string $key): ?array
{
    foreach ($options as $option) {
        if (($option['key'] ?? null) === $key) {
            return $option;
        }
    }
    return null;
}

function runtimeInspectorProject(string $bootstrap): string
{
    $locked = [[
        'name' => 'infocyph/foundation',
        'version' => '2.1.1',
        'source' => ['reference' => 'foundation-ref'],
    ]];
    $installed = [[
        'name' => 'infocyph/foundation',
        'version' => '2.1.1',
        'source' => ['reference' => 'foundation-ref'],
        'install-path' => '../infocyph/foundation',
    ]];

    return TempProject::create(
        composer: [
            'require' => ['infocyph/foundation' => '^2.1.1'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ],
        directories: ['app', 'bootstrap', 'vendor/composer', 'vendor/infocyph/foundation/src'],
        files: [
            'bootstrap/app.php' => $bootstrap,
            'composer.lock' => json_encode(['packages' => $locked, 'packages-dev' => []], JSON_THROW_ON_ERROR),
            'vendor/composer/installed.json' => json_encode(['packages' => $installed], JSON_THROW_ON_ERROR),
            'vendor/infocyph/foundation/src/Foundation.php' => runtimeFoundationFixture(),
        ],
    );
}

function runtimeFoundationFixture(): string
{
    return <<<'PHP'
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
PHP;
}
