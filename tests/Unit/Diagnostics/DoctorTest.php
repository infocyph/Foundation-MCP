<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Application;
use Infocyph\FoundationMcp\Diagnostics\Doctor;
use Infocyph\FoundationMcp\Tests\Support\TempProject;

it('runs the complete read-only doctor contract and honors no-git', function (): void {
    $root = TempProject::create(
        composer: [
            'require' => ['php' => '^8.4', 'infocyph/foundation' => '^2.1.1'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ],
        directories: [
            'app',
            'vendor/composer',
            'vendor/infocyph/foundation/src/Module',
        ],
        files: [
            'composer.lock' => json_encode(['packages' => [[
                'name' => 'infocyph/foundation',
                'version' => '2.1.1',
                'source' => ['reference' => 'foundation-ref'],
            ]], 'packages-dev' => []], JSON_THROW_ON_ERROR),
            'vendor/composer/installed.json' => json_encode(['packages' => [[
                'name' => 'infocyph/foundation',
                'version' => '2.1.1',
                'source' => ['reference' => 'foundation-ref'],
                'install-path' => '../infocyph/foundation',
            ]]], JSON_THROW_ON_ERROR),
            'vendor/infocyph/foundation/src/Module/ModuleCatalog.php' => <<<'PHP'
<?php
namespace Infocyph\Foundation\Module;
final class ModuleCatalog
{
    private const array MODULES = [
        'logging' => [
            'packages' => [],
            'built_in' => true,
            'description' => 'Logging.',
            'aliases' => ['log'],
            'config' => [],
            'schemas' => [],
        ],
    ];
}
PHP,
            'app/Example.php' => '<?php namespace App; final class Example {}',
        ],
    );

    try {
        $checks = (new Doctor($root, gitEnabled: false))->checks();
        $indexed = [];
        foreach ($checks as $check) {
            $indexed[$check['name']] = $check;
        }

        expect($indexed['Project detection']['ok'] ?? false)->toBeTrue()
            ->and($indexed['Composer state']['ok'] ?? false)->toBeTrue()
            ->and($indexed['Foundation package']['ok'] ?? false)->toBeTrue()
            ->and($indexed['Foundation ModuleCatalog']['ok'] ?? false)->toBeTrue()
            ->and($indexed['Path/security policy']['ok'] ?? false)->toBeTrue()
            ->and($indexed['Git inspection'] ?? null)->toMatchArray(['ok' => true, 'detail' => 'disabled by --no-git'])
            ->and($indexed['MCP server surface']['ok'] ?? false)->toBeTrue();

        expect(Application::run(['foundation-mcp', 'doctor', '--root='.$root, '--no-git']))->toBe(0);
    } finally {
        TempProject::remove($root);
    }
});
