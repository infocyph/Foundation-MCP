<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;

it('keeps declared locked and installed Composer truth distinct', function (): void {
    $root = TempProject::create(
        composer: [
            'name' => 'acme/app',
            'require' => [
                'php' => '^8.4',
                'infocyph/foundation' => '^2.1.1',
            ],
            'require-dev' => ['infocyph/phpforge' => 'dev-main@dev'],
        ],
        directories: [
            'vendor/composer',
            'vendor/infocyph/foundation',
            'vendor/infocyph/webrick',
            'vendor/infocyph/phpforge',
            'vendor/vendor/third',
        ],
        files: [
            'vendor/infocyph/foundation/composer.json' => json_encode([
                'name' => 'infocyph/foundation',
                'autoload' => ['psr-4' => ['Infocyph\\Foundation\\' => 'src/']],
                'suggest' => ['infocyph/cachelayer' => 'Adds cache support.'],
            ], JSON_THROW_ON_ERROR),
            'composer.lock' => json_encode([
                'packages' => [
                    [
                        'name' => 'infocyph/foundation',
                        'version' => '2.1.1',
                        'source' => ['reference' => 'foundation-ref'],
                        'require' => ['infocyph/webrick' => '^4.0'],
                    ],
                    [
                        'name' => 'infocyph/webrick',
                        'version' => '4.0.2',
                        'source' => ['reference' => 'webrick-ref'],
                    ],
                    [
                        'name' => 'vendor/third',
                        'version' => '1.0.0',
                    ],
                ],
                'packages-dev' => [[
                    'name' => 'infocyph/phpforge',
                    'version' => 'dev-main',
                    'source' => ['reference' => 'phpforge-ref'],
                ]],
            ], JSON_THROW_ON_ERROR),
            'vendor/composer/installed.json' => json_encode([
                'packages' => [
                    [
                        'name' => 'infocyph/foundation',
                        'version' => '2.1.1',
                        'source' => ['reference' => 'foundation-ref'],
                        'install-path' => '../infocyph/foundation',
                    ],
                    [
                        'name' => 'infocyph/webrick',
                        'version' => '4.0.2',
                        'source' => ['reference' => 'webrick-ref'],
                        'install-path' => '../infocyph/webrick',
                    ],
                    [
                        'name' => 'infocyph/phpforge',
                        'version' => 'dev-main',
                        'source' => ['reference' => 'phpforge-ref'],
                        'install-path' => '../infocyph/phpforge',
                    ],
                    [
                        'name' => 'vendor/third',
                        'version' => '1.0.0',
                        'install-path' => '../vendor/third',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ],
    );

    try {
        $project = (new ProjectDetector())->detect($root);
        $inspector = new ComposerInspector($project);
        $foundation = $inspector->foundation();
        $graph = $inspector->graph();

        expect($foundation)->not->toBeNull()
            ->and($foundation->declaredConstraint)->toBe('^2.1.1')
            ->and($foundation->declaredScope)->toBe('runtime')
            ->and($foundation->lockedVersion)->toBe('2.1.1')
            ->and($foundation->installedVersion)->toBe('2.1.1')
            ->and($foundation->lockedReference)->toBe('foundation-ref')
            ->and($foundation->installedReference)->toBe('foundation-ref')
            ->and($foundation->installPath)->toBe(realpath($root.'/vendor/infocyph/foundation'))
            ->and($foundation->state())->toBe('matched')
            ->and($foundation->autoload['psr-4'])->toHaveKey('Infocyph\\Foundation\\')
            ->and($foundation->suggest)->toHaveKey('infocyph/cachelayer')
            ->and($inspector->diagnostics())->toBe([])
            ->and($graph->runtimeDirect())->toBe(['infocyph/foundation'])
            ->and($graph->devDirect())->toBe(['infocyph/phpforge'])
            ->and($graph->dependencies('infocyph/foundation'))->toBe([[
                'package' => 'infocyph/webrick',
                'depth' => 1,
                'constraint' => '^4.0',
            ]])
            ->and($graph->dependents('infocyph/webrick'))->toBe(['infocyph/foundation'])
            ->and(array_keys($inspector->packageRoots()))->toBe([
                'infocyph/foundation',
                'infocyph/phpforge',
                'infocyph/webrick',
            ])
            ->and($inspector->packageRoots(['vendor/third']))->toHaveKey('vendor/third')
            ->and($inspector->platformRequirements())->toBe(['php' => ['constraint' => '^8.4', 'scope' => 'runtime']])
            ->and($inspector->ownerOfPath($root.'/vendor/infocyph/foundation/composer.json'))->toBe('infocyph/foundation');
    } finally {
        TempProject::remove($root);
    }
});

it('reports lock install version and source-reference divergence explicitly', function (): void {
    $root = TempProject::create(
        composer: [
            'require' => [
                'infocyph/foundation' => '^2.1',
                'infocyph/webrick' => '^4.0',
            ],
        ],
        directories: [
            'vendor/composer',
            'vendor/infocyph/foundation',
        ],
        files: [
            'composer.lock' => json_encode([
                'packages' => [
                    [
                        'name' => 'infocyph/foundation',
                        'version' => '2.1.1',
                        'source' => ['reference' => 'locked-ref'],
                    ],
                    [
                        'name' => 'infocyph/webrick',
                        'version' => '4.0.2',
                    ],
                ],
                'packages-dev' => [],
            ], JSON_THROW_ON_ERROR),
            'vendor/composer/installed.json' => json_encode([
                'packages' => [[
                    'name' => 'infocyph/foundation',
                    'version' => '2.1.0',
                    'source' => ['reference' => 'installed-ref'],
                    'install-path' => '../infocyph/foundation',
                ]],
            ], JSON_THROW_ON_ERROR),
        ],
    );

    try {
        $inspector = new ComposerInspector((new ProjectDetector())->detect($root));
        $diagnostics = $inspector->diagnostics();

        expect($inspector->foundation()?->state())->toBe('version_mismatch')
            ->and(array_column($diagnostics, 'code'))->toContain('version_mismatch', 'missing_install');
    } finally {
        TempProject::remove($root);
    }
});

it('reports missing lock and installed metadata without guessing exact versions', function (): void {
    $root = TempProject::create([
        'require' => ['infocyph/foundation' => '^2.1'],
    ]);

    try {
        $inspector = new ComposerInspector((new ProjectDetector())->detect($root));
        $foundation = $inspector->foundation();

        expect($foundation?->declaredConstraint)->toBe('^2.1')
            ->and($foundation?->lockedVersion)->toBeNull()
            ->and($foundation?->installedVersion)->toBeNull()
            ->and(array_column($inspector->diagnostics(), 'code'))->toContain(
                'composer_lock_missing',
                'installed_metadata_missing',
                'declared_unlocked',
            );
    } finally {
        TempProject::remove($root);
    }
});
