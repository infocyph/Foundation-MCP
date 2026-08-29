<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Analysis\TestLocator;
use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;

it('ranks related tests by proven references before structural and lexical evidence', function (): void {
    $root = TempProject::create(
        composer: [
            'name' => 'acme/app',
            'require' => ['infocyph/foundation' => '^2.1'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'autoload-dev' => ['psr-4' => [
                'Tests\\' => 'tests/',
                'Spec\\' => 'spec/',
            ]],
        ],
        directories: [
            'app/Domain',
            'tests/Unit/Domain',
            'tests/Other',
            'spec/Domain',
            'vendor/composer',
            'vendor/infocyph/foundation/src',
        ],
        files: [
            'app/Domain/PaymentService.php' => <<<'PHP_SOURCE'
<?php
namespace App\Domain;
final class PaymentService
{
    public static function charge(): void {}
}
PHP_SOURCE,
            'app/legacy.php' => '<?php // legacy source without declarations',
            'tests/Unit/Domain/ExactPaymentServiceTest.php' => <<<'PHP_SOURCE'
<?php
namespace Tests\Unit\Domain;
use App\Domain\PaymentService;
final class ExactPaymentServiceTest
{
    public function testPayment(): void
    {
        new PaymentService();
    }
}
PHP_SOURCE,
            'tests/Unit/Domain/StaticCallTest.php' => <<<'PHP_SOURCE'
<?php
namespace Tests\Unit\Domain;
use App\Domain\PaymentService;
final class StaticCallTest
{
    public function testCharge(): void
    {
        PaymentService::charge();
    }
}
PHP_SOURCE,
            'tests/Unit/Domain/PaymentServiceTest.php' => '<?php namespace Tests\\Unit\\Domain; final class PaymentServiceTest {}',
            'tests/Other/SmokeTest.php' => '<?php namespace Tests\\Other; // App\\Domain\\PaymentService is mentioned only lexically\n final class SmokeTest {}',
            'tests/Other/LegacyTest.php' => '<?php namespace Tests\\Other; // legacy compatibility coverage\n final class LegacyTest {}',
            'spec/Domain/PaymentServiceSpec.php' => '<?php namespace Spec\\Domain; final class PaymentServiceSpec {}',
            'vendor/infocyph/foundation/composer.json' => json_encode([
                'name' => 'infocyph/foundation',
                'autoload' => ['psr-4' => ['Infocyph\\Foundation\\' => 'src/']],
            ], JSON_THROW_ON_ERROR),
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
        $locator = new TestLocator($project, new ComposerInspector($project));
        $related = $locator->forSymbol('App\\Domain\\PaymentService');
        $byPath = array_column($related, null, 'path');

        expect($related[0]['path'])->toBe('tests/Unit/Domain/ExactPaymentServiceTest.php')
            ->and($related[0]['confidence'])->toBe('exact')
            ->and($related[0]['reasons'])->toContain('exact_symbol_reference')
            ->and($byPath['tests/Unit/Domain/StaticCallTest.php']['score'])->toBeGreaterThan(
                $byPath['tests/Unit/Domain/PaymentServiceTest.php']['score'],
            )
            ->and($byPath['tests/Unit/Domain/PaymentServiceTest.php']['confidence'])->toBe('lexical')
            ->and($byPath['tests/Unit/Domain/PaymentServiceTest.php']['reasons'])->toContain('filename_convention')
            ->and($byPath)->toHaveKey('spec/Domain/PaymentServiceSpec.php')
            ->and($byPath['tests/Other/SmokeTest.php']['reasons'])->toContain('lexical_fallback');

        $method = $locator->forSymbol('App\\Domain\\PaymentService::charge');
        expect($method[0]['path'])->toBe('tests/Unit/Domain/StaticCallTest.php')
            ->and($method[0]['confidence'])->toBe('exact');

        $file = $locator->forFile('./app/Domain/PaymentService.php');
        expect(array_column($file, 'path'))->toContain('tests/Unit/Domain/ExactPaymentServiceTest.php');

        $legacy = $locator->forFile('app/legacy.php');
        expect($legacy[0]['path'])->toBe('tests/Other/LegacyTest.php')
            ->and($legacy[0]['confidence'])->toBe('lexical')
            ->and($legacy[0]['reasons'])->toContain('lexical_fallback');

        expect($locator->forSymbol('App\\Domain\\Missing'))->toBe([])
            ->and(fn () => $locator->forSymbol('App\\Domain\\PaymentService', 101))->toThrow(InvalidArgumentException::class);
    } finally {
        TempProject::remove($root);
    }
});
