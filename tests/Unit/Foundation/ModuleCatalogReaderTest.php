<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Foundation\ModuleCatalogReader;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;

it('reads installed Foundation ModuleCatalog statically and correlates project state', function (): void {
    $root = moduleCatalogProject(<<<'PHP'
<?php

declare(strict_types=1);

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
        'logging' => [
            'packages' => [],
            'built_in' => true,
            'description' => 'Structured logging.',
            'aliases' => ['log', 'logs'],
            'config' => ['logging.php'],
            'schemas' => [],
        ],
    ];

    public function all(): array
    {
        throw new \RuntimeException('ModuleCatalog source must never be executed by Foundation MCP.');
    }
}
PHP,
        packageInstalled: true,
        configFiles: ['cache.php'],
    );

    try {
        $project = (new ProjectDetector())->detect($root);
        $reader = new ModuleCatalogReader($project, new ComposerInspector($project));
        $definitions = $reader->definitions();
        $modules = $reader->modules();

        expect(array_keys($definitions))->toBe(['cache', 'logging'])
            ->and($definitions['cache']['packages'])->toBe(['infocyph/cachelayer' => '^3.2.0'])
            ->and($definitions['cache']['schemas'])->toBe(['cache'])
            ->and($modules['cache']['status'])->toBe([
                'cataloged',
                'packages_installed',
                'config_present',
                'runtime_activation_unknown',
            ])
            ->and($modules['cache']['packages']['infocyph/cachelayer']['installed'])->toBeTrue()
            ->and($modules['logging']['status'])->toBe([
                'cataloged',
                'built_in',
                'config_missing',
                'runtime_activation_unknown',
            ])
            ->and((($reader->resolve('cachelayer'))['name'] ?? null))->toBe('cache')
            ->and((($reader->resolve('infocyph/cachelayer'))['name'] ?? null))->toBe('cache')
            ->and((($reader->resolve('LOGS'))['name'] ?? null))->toBe('logging')
            ->and($reader->resolve('unknown'))->toBeNull();
    } finally {
        TempProject::remove($root);
    }
});

it('reports missing module packages and configuration without guessing activation', function (): void {
    $root = moduleCatalogProject(<<<'PHP'
<?php

namespace Infocyph\Foundation\Module;

final class ModuleCatalog
{
    private const array MODULES = [
        'validation' => [
            'packages' => ['infocyph/reqshield' => '^3.1'],
            'description' => 'Validation and sanitization.',
            'aliases' => ['reqshield'],
            'config' => ['validation.php'],
            'schemas' => [],
        ],
    ];
}
PHP);

    try {
        $project = (new ProjectDetector())->detect($root);
        $module = (new ModuleCatalogReader($project, new ComposerInspector($project)))->modules()['validation'];

        expect($module['status'])->toBe([
            'cataloged',
            'packages_missing',
            'config_missing',
            'runtime_activation_unknown',
        ])
            ->and($module['packages']['infocyph/reqshield'])->toBe([
                'constraint' => '^3.1',
                'installed' => false,
                'state' => 'missing',
            ]);
    } finally {
        TempProject::remove($root);
    }
});

it('rejects executable expressions and ambiguous module resolution keys', function (): void {
    $root = moduleCatalogProject(<<<'PHP'
<?php

namespace Infocyph\Foundation\Module;

final class ModuleCatalog
{
    private const array MODULES = [
        'cache' => [
            'packages' => [],
            'description' => self::description(),
            'aliases' => [],
            'config' => [],
            'schemas' => [],
        ],
    ];

    private static function description(): string
    {
        return 'dynamic';
    }
}
PHP);

    try {
        $project = (new ProjectDetector())->detect($root);
        $reader = new ModuleCatalogReader($project, new ComposerInspector($project));

        expect(fn () => $reader->definitions())
            ->toThrow(RuntimeException::class, 'non-literal expression');
    } finally {
        TempProject::remove($root);
    }

    $root = moduleCatalogProject(<<<'PHP'
<?php

namespace Infocyph\Foundation\Module;

final class ModuleCatalog
{
    private const array MODULES = [
        'cache' => [
            'packages' => [],
            'description' => 'Cache.',
            'aliases' => ['shared'],
            'config' => [],
            'schemas' => [],
        ],
        'logging' => [
            'packages' => [],
            'description' => 'Logging.',
            'aliases' => ['shared'],
            'config' => [],
            'schemas' => [],
        ],
    ];
}
PHP);

    try {
        $project = (new ProjectDetector())->detect($root);
        $reader = new ModuleCatalogReader($project, new ComposerInspector($project));

        expect(fn () => $reader->definitions())
            ->toThrow(RuntimeException::class, 'ambiguous module key');
    } finally {
        TempProject::remove($root);
    }
});

/**
 * @param list<string> $configFiles
 */
function moduleCatalogProject(
    string $catalog,
    bool $packageInstalled = false,
    array $configFiles = [],
): string {
    $directories = [
        'vendor/composer',
        'vendor/infocyph/foundation/src/Module',
    ];
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

    if ($packageInstalled) {
        $directories[] = 'vendor/infocyph/cachelayer';
        $locked[] = [
            'name' => 'infocyph/cachelayer',
            'version' => '3.2.0',
            'source' => ['reference' => 'cache-ref'],
        ];
        $installed[] = [
            'name' => 'infocyph/cachelayer',
            'version' => '3.2.0',
            'source' => ['reference' => 'cache-ref'],
            'install-path' => '../infocyph/cachelayer',
        ];
    }

    $files = [
        'composer.lock' => json_encode([
            'packages' => $locked,
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR),
        'vendor/composer/installed.json' => json_encode([
            'packages' => $installed,
        ], JSON_THROW_ON_ERROR),
        'vendor/infocyph/foundation/src/Module/ModuleCatalog.php' => $catalog,
    ];

    foreach ($configFiles as $config) {
        $files['config/'.$config] = '<?php return [];';
    }

    return TempProject::create(
        composer: ['require' => ['infocyph/foundation' => '^2.1.1']],
        directories: $directories,
        files: $files,
    );
}
