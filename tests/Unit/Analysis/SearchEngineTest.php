<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Analysis\PhpAnalyzer;
use Infocyph\FoundationMcp\Analysis\SearchEngine;
use Infocyph\FoundationMcp\Analysis\SourceFileFinder;
use Infocyph\FoundationMcp\Analysis\SymbolIndex;
use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;
use PhpParser\ErrorHandler;
use PhpParser\Parser;
use PhpParser\ParserFactory;

it('searches symbols paths and text deterministically without parsing for filesystem-only kinds', function (): void {
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
            'routes',
            'docs',
            'config',
            'vendor/composer',
            'vendor/infocyph/foundation/src',
        ],
        files: [
            'app/PaymentService.php' => '<?php namespace App; final class PaymentService { public function charge(): void {} }',
            'tests/PaymentServiceTest.php' => '<?php namespace Tests; final class PaymentServiceTest {}',
            'routes/web.php' => "<?php\n// payment route marker\n",
            'docs/payments.md' => "# Payments\npayment route marker\n",
            'config/credentials.php' => '<?php return ["token" => "do-not-search"];',
            'vendor/infocyph/foundation/composer.json' => json_encode([
                'name' => 'infocyph/foundation',
                'autoload' => ['psr-4' => ['Infocyph\\Foundation\\' => 'src/']],
            ], JSON_THROW_ON_ERROR),
            'vendor/infocyph/foundation/src/PaymentRuntime.php' => '<?php namespace Infocyph\\Foundation; final class PaymentRuntime {}',
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
        $symbols = new SymbolIndex(
            $project,
            $composer,
            $analyzer,
            new SourceFileFinder($project, $composer),
        );
        $search = new SearchEngine($project, $composer, $symbols);

        $paths = $search->search('PaymentService.php', kind: 'path');
        $text = $search->search('payment route marker', scope: 'routes', kind: 'text');

        expect($parser->calls)->toBe(0)
            ->and($paths[0]['kind'])->toBe('path')
            ->and($paths[0]['path'])->toBe('app/PaymentService.php')
            ->and($text)->toHaveCount(1)
            ->and($text[0]['path'])->toBe('routes/web.php')
            ->and(array_column($search->search('do-not-search', kind: 'text'), 'path'))->not->toContain('config/credentials.php');

        $symbolResults = $search->search('PaymentService', kind: 'symbol');

        expect($parser->calls)->toBeGreaterThan(0)
            ->and($symbolResults[0]['symbol'])->toBe('App\\PaymentService')
            ->and($symbolResults[0]['score'])->toBeGreaterThan($symbolResults[1]['score'] ?? 0);

        $testResults = $search->search('PaymentServiceTest', scope: 'tests', kind: 'symbol');
        expect(array_column($testResults, 'path'))->toBe(['tests/PaymentServiceTest.php']);

        $foundation = $search->search('PaymentRuntime', scope: 'foundation', kind: 'symbol');
        expect($foundation[0]['package'])->toBe('infocyph/foundation')
            ->and($foundation[0]['symbol'])->toBe('Infocyph\\Foundation\\PaymentRuntime');

        expect(fn () => $search->search('x', scope: 'packages', kind: 'path'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $search->search('', kind: 'text'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $search->search('x', limit: 101))->toThrow(InvalidArgumentException::class);
    } finally {
        TempProject::remove($root);
    }
});
