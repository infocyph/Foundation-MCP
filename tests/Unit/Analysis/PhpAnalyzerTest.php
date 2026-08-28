<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Analysis\PhpAnalyzer;
use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;

$analyzerFixture = static function (array $files): array {
    $root = TempProject::create(
        composer: [
            'name' => 'acme/app',
            'require' => ['infocyph/foundation' => '^2.1'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ],
        directories: [
            'app',
            'vendor/composer',
            'vendor/infocyph/foundation/src',
        ],
        files: $files + [
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
    $project = (new ProjectDetector())->detect($root);
    $composer = new ComposerInspector($project);

    return [$root, new PhpAnalyzer($project, $composer)];
};

it('extracts compact declarations references imports and bounded literal arrays', function () use ($analyzerFixture): void {
    [$root, $analyzer] = $analyzerFixture([
        'app/Demo.php' => <<<'PHP'
<?php

namespace App\Service;

use Attribute;
use Vendor\Contracts\Runner as RunnerContract;

#[Attribute]
final readonly class Demo extends Base implements RunnerContract
{
    use HelperTrait;

    private const array OPTIONS = [
        'mode' => 'fast',
        'handler' => Thing::class,
        'nested' => [1, true],
    ];

    public function __construct(private readonly Clock $clock)
    {
    }

    /** Executes work. */
    public function run(Input|Other $input): Result
    {
        $thing = new Thing();
        Factory::make($thing);
        $this->local();
        $input->dispatch();

        return $thing;
    }
}

function helper(Thing $thing): Result
{
    return make_result($thing);
}

const VERSION = '1.0';

namespace App\Other;

final class Secondary
{
}
PHP,
        'vendor/infocyph/foundation/src/PackageThing.php' => <<<'PHP'
<?php

namespace Infocyph\Foundation;

final class PackageThing {}
PHP,
    ]);

    try {
        $result = $analyzer->project('app/Demo.php');
        $symbols = array_column($result->declarations, 'symbol');
        $class = current(array_filter(
            $result->declarations,
            static fn (array $declaration): bool => $declaration['symbol'] === 'App\\Service\\Demo',
        ));
        $method = current(array_filter(
            $result->declarations,
            static fn (array $declaration): bool => $declaration['symbol'] === 'App\\Service\\Demo::run',
        ));
        $references = array_map(
            static fn (array $reference): string => $reference['relationship'].':'.$reference['target'].':'.$reference['confidence'],
            $result->references,
        );

        expect($result->valid())->toBeTrue()
            ->and($result->scope)->toBe('project')
            ->and($result->path)->toBe('app/Demo.php')
            ->and($result->namespaces)->toBe(['App\\Service', 'App\\Other'])
            ->and($result->imports)->toContain([
                'namespace' => 'App\\Service',
                'kind' => 'class',
                'alias' => 'RunnerContract',
                'target' => 'Vendor\\Contracts\\Runner',
                'line' => 6,
            ])
            ->and($symbols)->toContain(
                'App\\Service\\Demo',
                'App\\Service\\Demo::$clock',
                'App\\Service\\Demo::run',
                'App\\Service\\Demo::OPTIONS',
                'App\\Service\\helper',
                'App\\Service\\VERSION',
                'App\\Other\\Secondary',
            )
            ->and($class['extends'])->toBe(['App\\Service\\Base'])
            ->and($class['implements'])->toBe(['Vendor\\Contracts\\Runner'])
            ->and($class['traits'])->toBe(['App\\Service\\HelperTrait'])
            ->and($class['attributes'])->toBe(['Attribute'])
            ->and($method['type'])->toBe('App\\Service\\Result')
            ->and($method['parameters'][0]['type'])->toBe('App\\Service\\Input|App\\Service\\Other')
            ->and($method['doc'])->toBe('Executes work.')
            ->and($references)->toContain(
                'extends:App\\Service\\Base:resolved',
                'implements:Vendor\\Contracts\\Runner:resolved',
                'trait-use:App\\Service\\HelperTrait:resolved',
                'new:App\\Service\\Thing:resolved',
                'call:App\\Service\\Factory::make:resolved',
                'call:App\\Service\\Demo::local:resolved',
                'call:dispatch:lexical',
                'call:App\\Service\\make_result:lexical',
            )
            ->and($result->literalArrays)->toContain([
                'line' => 13,
                'value' => [
                    'mode' => 'fast',
                    'handler' => 'App\\Service\\Thing',
                    'nested' => [1, true],
                ],
            ]);

        $package = $analyzer->package('infocyph/foundation', 'src/PackageThing.php');

        expect($package->scope)->toBe('package')
            ->and($package->package)->toBe('infocyph/foundation')
            ->and(array_column($package->declarations, 'symbol'))->toContain('Infocyph\\Foundation\\PackageThing');
    } finally {
        TempProject::remove($root);
    }
});

it('returns file-local parse errors without throwing away analyzer availability', function () use ($analyzerFixture): void {
    [$root, $analyzer] = $analyzerFixture([
        'app/Broken.php' => '<?php final class Broken { public function nope( { }',
        'app/Good.php' => '<?php final class Good {}',
    ]);

    try {
        $broken = $analyzer->project('app/Broken.php');
        $good = $analyzer->project('app/Good.php');

        expect($broken->valid())->toBeFalse()
            ->and($broken->errors[0]['code'])->toBe('parse_error')
            ->and($good->valid())->toBeTrue()
            ->and(array_column($good->declarations, 'symbol'))->toContain('Good');
    } finally {
        TempProject::remove($root);
    }
});

it('reuses unchanged analysis and invalidates it by content fingerprint', function () use ($analyzerFixture): void {
    [$root, $analyzer] = $analyzerFixture([
        'app/Cached.php' => '<?php final class Before {}',
    ]);

    try {
        $first = $analyzer->project('app/Cached.php');
        $second = $analyzer->project('app/Cached.php');

        expect($second)->toBe($first);

        file_put_contents($root.'/app/Cached.php', '<?php final class After {}');
        $third = $analyzer->project('app/Cached.php');

        expect($third)->not->toBe($first)
            ->and($third->fingerprint)->not->toBe($first->fingerprint)
            ->and(array_column($third->declarations, 'symbol'))->toContain('After');
    } finally {
        TempProject::remove($root);
    }
});

it('enforces source type and secret policy before parsing', function () use ($analyzerFixture): void {
    [$root, $analyzer] = $analyzerFixture([
        'app/credentials.php' => '<?php return ["token" => "secret"];',
        'app/readme.txt' => 'not php',
    ]);

    try {
        expect(fn () => $analyzer->project('app/credentials.php'))
            ->toThrow(\RuntimeException::class, 'Secret-bearing resource is denied.')
            ->and(fn () => $analyzer->project('app/readme.txt'))
            ->toThrow(\RuntimeException::class, 'PHP analysis accepts only .php source files.');
    } finally {
        TempProject::remove($root);
    }
});
