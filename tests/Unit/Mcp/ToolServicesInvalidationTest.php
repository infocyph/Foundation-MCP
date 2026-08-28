<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Mcp\Tool\ToolServices;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;

it('invalidates the shared lazy service graph when Composer metadata changes', function (): void {
    $root = TempProject::create(
        composer: [
            'require' => ['infocyph/foundation' => '^2.1'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ],
        directories: ['app', 'vendor/composer', 'vendor/infocyph/foundation'],
        files: [
            'composer.lock' => json_encode([
                'packages' => [[
                    'name' => 'infocyph/foundation',
                    'version' => '2.1.1',
                    'source' => ['reference' => 'one'],
                ]],
                'packages-dev' => [],
            ], JSON_THROW_ON_ERROR),
            'vendor/composer/installed.json' => json_encode(['packages' => [[
                'name' => 'infocyph/foundation',
                'version' => '2.1.1',
                'source' => ['reference' => 'one'],
                'install-path' => '../infocyph/foundation',
            ]]], JSON_THROW_ON_ERROR),
        ],
    );

    try {
        $services = new ToolServices((new ProjectDetector())->detect($root), gitEnabled: false);
        $first = $services->composer();
        expect($first->foundation()?->lockedVersion)->toBe('2.1.1');

        file_put_contents($root.'/composer.lock', json_encode([
            'packages' => [[
                'name' => 'infocyph/foundation',
                'version' => '2.10.0',
                'source' => ['reference' => 'two-longer'],
            ]],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));

        $second = $services->composer();
        expect($second)->not->toBe($first)
            ->and($second->foundation()?->lockedVersion)->toBe('2.10.0');
    } finally {
        TempProject::remove($root);
    }
});
