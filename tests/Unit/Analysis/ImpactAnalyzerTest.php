<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Analysis\ImpactAnalyzer;
use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;

it('builds deterministic bounded impact evidence for symbols files packages modules routes config and workspace changes', function (): void {
    $locked = [
        impactPackage('infocyph/foundation', '2.1.1', 'foundation-ref'),
        impactPackage('infocyph/webrick', '4.0.2', 'webrick-ref'),
        impactPackage('infocyph/cachelayer', '3.2.0', 'cache-ref', ['Infocyph\\Cache\\' => 'src/']),
    ];
    $installed = [
        ['name' => 'infocyph/foundation', 'version' => '2.1.1', 'source' => ['reference' => 'foundation-ref'], 'install-path' => '../infocyph/foundation'],
        ['name' => 'infocyph/webrick', 'version' => '4.0.2', 'source' => ['reference' => 'webrick-ref'], 'install-path' => '../infocyph/webrick'],
        ['name' => 'infocyph/cachelayer', 'version' => '3.2.0', 'source' => ['reference' => 'cache-ref'], 'install-path' => '../infocyph/cachelayer', 'autoload' => ['psr-4' => ['Infocyph\\Cache\\' => 'src/']]],
    ];

    $root = TempProject::create(
        composer: [
            'require' => ['infocyph/foundation' => '^2.1', 'infocyph/cachelayer' => '^3.2'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
        ],
        directories: [
            'app', 'tests', 'routes', 'config', 'vendor/composer',
            'vendor/infocyph/foundation/src/Routing', 'vendor/infocyph/foundation/src/Module',
            'vendor/infocyph/webrick/src/Constants', 'vendor/infocyph/cachelayer/src',
        ],
        files: [
            'composer.lock' => json_encode(['packages' => $locked, 'packages-dev' => []], JSON_THROW_ON_ERROR),
            'vendor/composer/installed.json' => json_encode(['packages' => $installed], JSON_THROW_ON_ERROR),
            'vendor/infocyph/foundation/src/Module/ModuleCatalog.php' => impactModuleCatalog(),
            'vendor/infocyph/foundation/src/Routing/RouteFileLoader.php' => impactRouteFileLoader(),
            'vendor/infocyph/foundation/src/Routing/OAuthRouteRegistrar.php' => "<?php namespace Infocyph\\Foundation\\Routing; final class OAuthRouteRegistrar { public function register(\$router): void {} }",
            'vendor/infocyph/webrick/src/Constants/HttpMethodEnum.php' => impactHttpMethods(),
            'app/Service.php' => <<<'PHP'
<?php
namespace App;
final class Service
{
    public function handle(): void {}
}
PHP,
            'app/Consumer.php' => <<<'PHP'
<?php
namespace App;
final class Consumer
{
    public function run(Service $service): void { $service->handle(); }
}
PHP,
            'app/CacheConsumer.php' => <<<'PHP'
<?php
namespace App;
use Infocyph\Cache\CacheClient;
final class CacheConsumer
{
    public function cache(): CacheClient { return new CacheClient(); }
}
PHP,
            'tests/ServiceTest.php' => <<<'PHP'
<?php
namespace Tests;
use App\Service;
final class ServiceTest
{
    public function testService(): void { (new Service())->handle(); }
}
PHP,
            'routes/web.php' => <<<'PHP'
<?php
use App\Service;
$router->get('/service', [Service::class, 'handle'], 'service');
PHP,
            'config/app.php' => <<<'PHP'
<?php
use App\Service;
return ['service' => Service::class];
PHP,
            'config/cache.php' => "<?php return ['enabled' => true];\n",
        ],
    );

    try {
        impactGit($root, ['init', '-q']);
        impactGit($root, ['config', 'user.email', 'test@example.com']);
        impactGit($root, ['config', 'user.name', 'Foundation MCP Test']);
        impactGit($root, ['add', '.']);
        impactGit($root, ['commit', '-qm', 'baseline']);

        file_put_contents($root.'/app/Service.php', <<<'PHP'
<?php
namespace App;
final class Service
{
    public function handle(): void {}
    public function changed(): bool { return true; }
}
PHP);

        $project = (new ProjectDetector())->detect($root);
        $impact = new ImpactAnalyzer($project, new ComposerInspector($project));

        $symbol = $impact->analyze('symbol', 'App\\Service');
        expect($symbol['affected_files'])->toContain('app/Consumer.php', 'tests/ServiceTest.php')
            ->and($symbol['related_tests'])->toContain('tests/ServiceTest.php');

        $file = $impact->analyze('file', 'app/Service.php');
        expect($file['affected_symbols'])->toContain('App\\Service')
            ->and($file['related_tests'])->toContain('tests/ServiceTest.php');

        $package = $impact->analyze('package', 'infocyph/cachelayer');
        expect($package['affected_files'])->toContain('app/CacheConsumer.php')
            ->and($package['affected_modules'])->toContain('cache');

        $module = $impact->analyze('module', 'cache');
        expect($module['affected_packages'])->toContain('infocyph/cachelayer')
            ->and($module['affected_files'])->toContain('config/cache.php');

        $route = $impact->analyze('route', 'service');
        expect(array_any($route['evidence'], static fn (array $item): bool => $item['type'] === 'route'))->toBeTrue();

        $config = $impact->analyze('config', 'app.service');
        expect(array_any($config['evidence'], static fn (array $item): bool => $item['type'] === 'config' && $item['target'] === 'app.service'))->toBeTrue();

        $changes = $impact->analyze('changes');
        expect($changes['affected_files'])->toContain('app/Service.php')
            ->and($changes['affected_symbols'])->toContain('App\\Service::changed');

        expect(fn () => $impact->analyze('symbol', 'App\\Service', 201))->toThrow(InvalidArgumentException::class);
    } finally {
        TempProject::remove($root);
    }
});

/** @param array<string,string> $psr4 @return array<string,mixed> */
function impactPackage(string $name, string $version, string $reference, array $psr4 = []): array
{
    $package = ['name' => $name, 'version' => $version, 'source' => ['reference' => $reference]];
    if ($psr4 !== []) {
        $package['autoload'] = ['psr-4' => $psr4];
    }
    return $package;
}

function impactModuleCatalog(): string
{
    return <<<'PHP'
<?php
namespace Infocyph\Foundation\Module;
final class ModuleCatalog
{
    private const array MODULES = [
        'cache' => [
            'packages' => ['infocyph/cachelayer' => '^3.2'],
            'built_in' => false,
            'description' => 'Cache support.',
            'aliases' => ['cachelayer'],
            'config' => ['cache.php'],
            'schemas' => [],
        ],
    ];
}
PHP;
}

function impactRouteFileLoader(): string
{
    return <<<'PHP'
<?php
namespace Infocyph\Foundation\Routing;
final readonly class RouteFileLoader
{
    public function __construct(private array $files = ['web.php']) {}
}
PHP;
}

function impactHttpMethods(): string
{
    return <<<'PHP'
<?php
namespace Infocyph\Webrick\Constants;
enum HttpMethodEnum: string
{
    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case PATCH = 'PATCH';
    case DELETE = 'DELETE';
    case HEAD = 'HEAD';
    case OPTIONS = 'OPTIONS';
}
PHP;
}

/** @param list<string> $args */
function impactGit(string $root, array $args): string
{
    $process = proc_open(['git', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Git is required for this test.');
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException('Git test command failed: '.$err);
    }
    return $out;
}
