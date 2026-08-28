<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Analysis\PhpAnalyzer;
use Infocyph\FoundationMcp\Analysis\ReferenceIndex;
use Infocyph\FoundationMcp\Analysis\SourceFileFinder;
use Infocyph\FoundationMcp\Analysis\SymbolIndex;
use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;
use PhpParser\ErrorHandler;
use PhpParser\Parser;
use PhpParser\ParserFactory;

it('builds lazy usage intelligence with exact resolved lexical and dynamic confidence', function (): void {
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
        files: [
            'app/Definitions.php' => <<<'PHP'
<?php

namespace App;

class Base {}
interface Contract {}
trait Helper {}

final class Target
{
    public static function make(): self
    {
        return new self();
    }
}

function helper(): void {}
PHP,
            'app/User.php' => <<<'PHP'
<?php

namespace App;

use App\Target as Alias;

final class User extends Base implements Contract
{
    use Helper;

    public function local(): void {}

    public function run(): Target
    {
        $target = new Alias();
        Alias::make();
        $this->local();
        $unknown->dispatch();
        helper();
        $class = Alias::class;
        $dynamic = fn (string $method) => $this->{$method}();

        return $target;
    }
}
PHP,
            'app/Global.php' => '<?php function dispatch(): void {}',
            'app/Broken.php' => '<?php final class Broken { public function nope( { }',
            'vendor/infocyph/foundation/composer.json' => json_encode([
                'name' => 'infocyph/foundation',
                'autoload' => ['psr-4' => ['Infocyph\\Foundation\\' => 'src/']],
            ], JSON_THROW_ON_ERROR),
            'vendor/infocyph/foundation/src/PackageUsage.php' => <<<'PHP'
<?php

namespace Infocyph\Foundation;

final class PackageTarget {}

final class PackageUsage
{
    public function make(): PackageTarget
    {
        return new PackageTarget();
    }
}
PHP,
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
        $finder = new SourceFileFinder($project, $composer);
        $symbols = new SymbolIndex($project, $composer, $analyzer, $finder);
        $references = new ReferenceIndex($project, $composer, $analyzer, $finder, $symbols);

        expect($parser->calls)->toBe(0);

        $projectReferences = $references->project();
        $firstCalls = $parser->calls;

        expect($firstCalls)->toBeGreaterThan(0)
            ->and(array_column($references->diagnostics(), 'code'))->toContain('parse_error');

        $targetUsages = $references->usages('App\\Target');
        $targetRelationships = array_column($targetUsages, 'relationship');

        expect($targetRelationships)->toContain('import', 'new', 'type')
            ->and(array_unique(array_column($targetUsages, 'confidence')))->toBe(['exact']);

        $static = $references->usages('App\\Target::make');
        expect($static)->toHaveCount(1)
            ->and($static[0]['confidence'])->toBe('exact')
            ->and($static[0]['source_symbol'])->toBe('App\\User::run');

        $local = $references->usages('App\\User::local');
        expect($local)->toHaveCount(1)
            ->and($local[0]['confidence'])->toBe('exact')
            ->and($local[0]['source_symbol'])->toBe('App\\User::run');

        $helper = $references->usages('App\\helper');
        expect($helper)->toHaveCount(1)
            ->and($helper[0]['confidence'])->toBe('exact');

        $dispatch = $references->usages('dispatch');
        expect($dispatch)->toHaveCount(1)
            ->and($dispatch[0]['confidence'])->toBe('lexical')
            ->and($dispatch[0]['source_symbol'])->toBe('App\\User::run');

        $dynamic = array_values(array_filter(
            $projectReferences,
            static fn (array $reference): bool => $reference['confidence'] === 'dynamic',
        ));
        expect($dynamic)->not->toBe([]);

        $filtered = $references->usages('App\\Target', relationships: ['new']);
        expect(array_unique(array_column($filtered, 'relationship')))->toBe(['new']);

        $references->project();
        expect($parser->calls)->toBe($firstCalls);

        file_put_contents(
            $root.'/app/User.php',
            file_get_contents($root.'/app/User.php')."\n// changed for incremental refresh\n",
        );
        $references->project();
        expect($parser->calls)->toBe($firstCalls + 1);

        $beforePackage = $parser->calls;
        $packageReferences = $references->package('infocyph/foundation');

        expect($parser->calls)->toBe($beforePackage + 1)
            ->and($references->usages(
                'Infocyph\\Foundation\\PackageTarget',
                'infocyph/foundation',
                ['new'],
            ))->toHaveCount(1)
            ->and(array_column($packageReferences, 'confidence'))->toContain('exact');

        expect(fn () => $references->usages('App\\Target', limit: 501))
            ->toThrow(\InvalidArgumentException::class, 'Usage result limit must be between 1 and 500.');
    } finally {
        TempProject::remove($root);
    }
});
