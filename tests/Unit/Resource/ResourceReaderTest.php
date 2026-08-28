<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Resource\ResourceReader;
use Infocyph\FoundationMcp\Tests\Support\TempProject;

it('reads bounded redacted project and explicit package resources', function (): void {
    $root = TempProject::create(
        composer: [
            'name' => 'acme/app',
            'require' => ['infocyph/foundation' => '^2.1'],
        ],
        directories: [
            'docs',
            'vendor/composer',
            'vendor/infocyph/foundation/src',
        ],
        files: [
            'docs/guide.md' => "one\ntoken = secret-value\nthree\nfour\n",
            '.env' => "APP_KEY=never-read\n",
            'vendor/infocyph/foundation/composer.json' => json_encode([
                'name' => 'infocyph/foundation',
                'autoload' => ['psr-4' => ['Infocyph\\Foundation\\' => 'src/']],
            ], JSON_THROW_ON_ERROR),
            'vendor/infocyph/foundation/src/Runtime.php' => "<?php\nnamespace Infocyph\\Foundation;\nfinal class Runtime {}\n",
            'composer.lock' => json_encode([
                'packages' => [[
                    'name' => 'infocyph/foundation',
                    'version' => '2.1.1',
                    'source' => ['reference' => 'foundation-ref'],
                ]],
                'packages-dev' => [],
            ], JSON_THROW_ON_ERROR),
            'vendor/composer/installed.json' => json_encode([
                'packages' => [[
                    'name' => 'infocyph/foundation',
                    'version' => '2.1.1',
                    'source' => ['reference' => 'foundation-ref'],
                    'install-path' => '../infocyph/foundation',
                ]],
            ], JSON_THROW_ON_ERROR),
        ],
    );

    try {
        $project = (new ProjectDetector())->detect($root);
        $reader = new ResourceReader($project, new ComposerInspector($project));
        $projectRead = $reader->project('docs/guide.md', 2, 3);

        expect($projectRead['scope'])->toBe('project')
            ->and($projectRead['path'])->toBe('docs/guide.md')
            ->and($projectRead['start_line'])->toBe(2)
            ->and($projectRead['end_line'])->toBe(3)
            ->and($projectRead['truncated'])->toBeTrue()
            ->and($projectRead['content'])->toContain('[REDACTED]')
            ->and($projectRead['content'])->not->toContain('secret-value')
            ->and($projectRead['fingerprint'])->toHaveLength(64);

        $packageRead = $reader->package('infocyph/foundation', 'src/Runtime.php', 1, 2);

        expect($packageRead['scope'])->toBe('package')
            ->and($packageRead['package'])->toBe('infocyph/foundation')
            ->and($packageRead['content'])->toContain('namespace Infocyph\\Foundation');

        expect(fn () => $reader->project('.env'))->toThrow(RuntimeException::class)
            ->and(fn () => $reader->project('docs/guide.md', 1, 401))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $reader->project('../docs/guide.md'))->toThrow(RuntimeException::class);
    } finally {
        TempProject::remove($root);
    }
});
