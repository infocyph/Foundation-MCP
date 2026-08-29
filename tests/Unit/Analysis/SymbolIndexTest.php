<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Analysis\PhpAnalyzer;
use Infocyph\FoundationMcp\Analysis\SourceFileFinder;
use Infocyph\FoundationMcp\Analysis\SymbolIndex;
use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;
use PhpParser\ErrorHandler;
use PhpParser\Parser;
use PhpParser\ParserFactory;

it('builds project and package symbol indexes lazily and refreshes only changed files', function (): void {
    $root = TempProject::create(
        composer: [
            'name' => 'acme/app',
            'require' => ['infocyph/foundation' => '^2.1'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
        ],
        directories: [
            'app',
            'tests',
            'config',
            'vendor/composer',
            'vendor/infocyph/foundation/src',
        ],
        files: [
            'app/Service.php' => '<?php namespace App; final class Service {}',
            'tests/ServiceTest.php' => '<?php namespace Tests; final class ServiceTest {}',
            'tests/Broken.php' => '<?php final class Broken { public function nope( { }',
            'config/helpers.php' => '<?php namespace App; function helper(): void {}',
            'config/duplicate.php' => '<?php namespace App; final class Service {}',
            'config/credentials.php' => '<?php namespace App; final class MustNotBeIndexed {}',
            'vendor/infocyph/foundation/composer.json' => json_encode([
                'name' => 'infocyph/foundation',
                'autoload' => ['psr-4' => ['Infocyph\\Foundation\\' => 'src/']],
            ], JSON_THROW_ON_ERROR),
            'vendor/infocyph/foundation/src/PackageService.php' => '<?php namespace Infocyph\\Foundation; final class PackageService {}',
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
        $composer = new ComposerInspector($project);
        $native = (new ParserFactory())->createForNewestSupportedVersion();
        $parser = new class($native) implements Parser {
            public int $calls = 0;

            public function __construct(private readonly Parser $inner)
            {
            }

            public function parse(string $code, ?ErrorHandler $errorHandler = null): ?array
            {
                ++$this->calls;

                return $this->inner->parse($code, $errorHandler);
            }

            public function getTokens(): array
            {
                return $this->inner->getTokens();
            }
        };
        $analyzer = new PhpAnalyzer($project, $composer, $parser);
        $index = new SymbolIndex(
            $project,
            $composer,
            $analyzer,
            new SourceFileFinder($project, $composer),
        );

        expect($parser->calls)->toBe(0);

        $projectSymbols = $index->project();
        $firstCalls = $parser->calls;
        $symbols = array_column($projectSymbols, 'symbol');

        expect($firstCalls)->toBeGreaterThan(0)
            ->and($symbols)->toContain('App\\Service', 'Tests\\ServiceTest', 'App\\helper')
            ->and($symbols)->not->toContain('App\\MustNotBeIndexed')
            ->and($index->find('app\\service'))->toHaveCount(2)
            ->and(array_column($index->diagnostics(), 'code'))->toContain('parse_error');

        $index->project();
        expect($parser->calls)->toBe($firstCalls);

        file_put_contents($root.'/app/Service.php', '<?php namespace App; final class Service { public function changed(): void {} }');
        $updated = $index->project();

        expect($parser->calls)->toBe($firstCalls + 1)
            ->and(array_column($updated, 'symbol'))->toContain('App\\Service::changed');

        $beforePackage = $parser->calls;
        $package = $index->package('infocyph/foundation');

        expect($parser->calls)->toBe($beforePackage + 1)
            ->and(array_column($package, 'symbol'))->toContain('Infocyph\\Foundation\\PackageService')
            ->and($index->find('Infocyph\\Foundation\\PackageService', 'infocyph/foundation'))->toHaveCount(1);

        unlink($root.'/config/duplicate.php');
        expect($index->find('App\\Service'))->toHaveCount(1);
    } finally {
        TempProject::remove($root);
    }
});
