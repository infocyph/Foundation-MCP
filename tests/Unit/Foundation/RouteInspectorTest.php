<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Foundation\RouteInspector;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;

it('inspects authoritative Foundation and Webrick routes without bootstrapping the application', function (): void {
    $root = routeInspectorProject([
        'routes/api.php' => <<<'PHP'
<?php

use App\Http\Controllers\UserController;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router;

$router->get('/health', UserController::health(...), 'health');
Router::head('/head', [UserController::class, 'head'], 'head');
$router->get($dynamicPath, [UserController::class, 'dynamic'], 'dynamic');

$router->group(
    ['prefix' => '/v1', 'middleware' => ['auth'], 'name' => 'v1.'],
    function (Registrar $r): void {
        $r->post(
            '/users',
            [UserController::class, 'store'],
            ['as' => 'users.store', 'middleware' => ['json']],
        );
    },
);

$router->resource(
    'users',
    '/users',
    UserController::class,
    ['only' => ['index', 'show'], 'middleware' => ['auth']],
);

if ($featureEnabled) {
    $router->delete('/danger', [UserController::class, 'destroy']);
}
PHP,
        'routes/auth.php' => <<<'PHP'
<?php

use App\Http\Controllers\UserController;
use Infocyph\Webrick\Router\Definition\Registrar;

$presets->group(
    $router,
    'web-auth',
    function (Registrar $r): void {
        $r->get('/login', [UserController::class, 'login'], 'login');
    },
    prefix: '/auth',
    namePrefix: 'auth.',
);
PHP,
        'routes/extra.php' => <<<'PHP'
<?php
$router->get('/must-not-appear', fn () => null);
PHP,
        'config/router.php' => <<<'PHP'
<?php
return ['attributes' => ['enabled' => true]];
PHP,
        'app/Http/Controllers/AdminController.php' => <<<'PHP'
<?php

namespace App\Http\Controllers;

use Infocyph\Webrick\Router\Definition\Attribute\Group;
use Infocyph\Webrick\Router\Definition\Attribute\Middleware;
use Infocyph\Webrick\Router\Definition\Attribute\Route;

#[Group(prefix: '/admin', middleware: ['auth'], name: 'admin.')]
#[Middleware(['class'])]
final class AdminController
{
    #[Route(method: ['GET', 'POST'], path: '/dashboard', name: 'dashboard', middleware: ['audit'])]
    #[Middleware(['method'])]
    public function dashboard(): void {}
}
PHP,
    ]);

    try {
        $project = (new ProjectDetector())->detect($root);
        $result = (new RouteInspector($project, new ComposerInspector($project)))->inspect();
        $routes = $result['routes'];

        expect($result['route_files'])->toBe(['web.php', 'api.php', 'auth.php'])
            ->and($result['http_methods'])->toContain('GET', 'POST', 'DELETE', 'PATCH')
            ->and(routeByName($routes, 'health'))->toMatchArray([
                'method' => 'GET',
                'path' => '/health',
                'handler' => 'App\\Http\\Controllers\\UserController::health',
                'status' => 'resolved',
                'conditional' => false,
            ])
            ->and(routeByName($routes, 'head'))->toMatchArray([
                'method' => 'HEAD',
                'path' => '/head',
                'status' => 'resolved',
            ])
            ->and(routeByName($routes, 'dynamic'))->toMatchArray([
                'path' => null,
                'status' => 'dynamic',
                'dynamic_fields' => ['path'],
            ])
            ->and(routeByName($routes, 'v1.users.store'))->toMatchArray([
                'method' => 'POST',
                'path' => '/v1/users',
                'middleware' => ['auth', 'json'],
                'handler' => 'App\\Http\\Controllers\\UserController::store',
            ])
            ->and(routeByName($routes, 'users.index'))->toMatchArray([
                'method' => 'GET',
                'path' => '/users',
                'handler' => 'App\\Http\\Controllers\\UserController::index',
            ])
            ->and(routeByName($routes, 'users.show'))->toMatchArray([
                'method' => 'GET',
                'path' => '/users/{id}',
                'handler' => 'App\\Http\\Controllers\\UserController::show',
            ])
            ->and(routeByName($routes, 'auth.login'))->toMatchArray([
                'path' => '/auth/login',
                'middleware' => ['@preset:web-auth'],
                'conditional' => false,
                'status' => 'dynamic',
            ])
            ->and(routeByName($routes, 'admin.dashboard', 'GET'))->toMatchArray([
                'path' => '/admin/dashboard',
                'handler' => 'App\\Http\\Controllers\\AdminController::dashboard',
                'middleware' => ['auth', 'class', 'method'],
                'options' => [
                    'attribute' => true,
                    'declared_route_middleware' => ['audit'],
                ],
                'origin' => 'project_attribute',
                'conditional' => false,
            ])
            ->and(routeByPath($routes, '/danger'))->toMatchArray([
                'method' => 'DELETE',
                'conditional' => true,
            ])
            ->and(routeByName($routes, 'oauth.metadata'))->toMatchArray([
                'origin' => 'foundation',
                'conditional' => true,
                'path' => '/.well-known/oauth-authorization-server',
            ])
            ->and(routeByHandler($routes, 'Infocyph\\Foundation\\Auth\\OAuth\\Http\\OAuthHttpHandler::token'))->toMatchArray([
                'origin' => 'foundation',
                'path' => null,
                'status' => 'dynamic',
                'conditional' => true,
            ])
            ->and(routeByPath($routes, '/must-not-appear'))->toBeNull();
    } finally {
        TempProject::remove($root);
    }
});

it('isolates route parse failures and respects explicit attribute disablement', function (): void {
    $root = routeInspectorProject([
        'routes/api.php' => '<?php $router->get(',
        'routes/auth.php' => "<?php \$router->get('/login', fn () => null, 'login');",
        'config/router.php' => "<?php return ['attributes' => ['enabled' => false]];",
        'app/Http/Controllers/AdminController.php' => <<<'PHP'
<?php
namespace App\Http\Controllers;
use Infocyph\Webrick\Router\Definition\Attribute\Route;
final class AdminController
{
    #[Route(method: 'GET', path: '/admin')]
    public function index(): void {}
}
PHP,
    ]);

    try {
        $project = (new ProjectDetector())->detect($root);
        $result = (new RouteInspector($project, new ComposerInspector($project)))->inspect();

        expect(routeByName($result['routes'], 'login'))->not->toBeNull()
            ->and(array_filter(
                $result['routes'],
                static fn (array $route): bool => $route['origin'] === 'project_attribute',
            ))->toBe([])
            ->and(array_any(
                $result['diagnostics'],
                static fn (array $item): bool => $item['code'] === 'parse_error' && $item['source'] === 'routes/api.php',
            ))->toBeTrue();
    } finally {
        TempProject::remove($root);
    }
});


it('reports an invalid installed routing contract without executing package source', function (): void {
    $root = routeInspectorProject([]);

    try {
        file_put_contents(
            $root.'/vendor/infocyph/foundation/src/Routing/RouteFileLoader.php',
            <<<'PHP'
<?php
namespace Infocyph\Foundation\Routing;
final class RouteFileLoader
{
    public function __construct(private array $files = self::files()) {}
    private static function files(): array { throw new \RuntimeException('must not execute'); }
}
PHP,
        );

        $project = (new ProjectDetector())->detect($root);
        $result = (new RouteInspector($project, new ComposerInspector($project)))->inspect();

        expect($result['routes'])->toBe([])
            ->and($result['route_files'])->toBe([])
            ->and($result['http_methods'])->toBe([])
            ->and($result['diagnostics'][0]['code'] ?? null)->toBe('routing_contract_invalid');
    } finally {
        TempProject::remove($root);
    }
});

/** @param list<array<string,mixed>> $routes */
function routeByName(array $routes, string $name, ?string $method = null): ?array
{
    foreach ($routes as $route) {
        if ($route['name'] === $name && ($method === null || $route['method'] === $method)) {
            return $route;
        }
    }
    return null;
}

/** @param list<array<string,mixed>> $routes */
function routeByPath(array $routes, string $path): ?array
{
    foreach ($routes as $route) {
        if ($route['path'] === $path) {
            return $route;
        }
    }
    return null;
}

/** @param list<array<string,mixed>> $routes */
function routeByHandler(array $routes, string $handler): ?array
{
    foreach ($routes as $route) {
        if ($route['handler'] === $handler) {
            return $route;
        }
    }
    return null;
}

/** @param array<string,string> $projectFiles */
function routeInspectorProject(array $projectFiles): string
{
    $locked = [
        [
            'name' => 'infocyph/foundation',
            'version' => '2.1.1',
            'source' => ['reference' => 'foundation-ref'],
            'require' => ['infocyph/webrick' => '^4.0.2'],
        ],
        [
            'name' => 'infocyph/webrick',
            'version' => '4.0.2',
            'source' => ['reference' => 'webrick-ref'],
        ],
    ];
    $installed = [
        [
            'name' => 'infocyph/foundation',
            'version' => '2.1.1',
            'source' => ['reference' => 'foundation-ref'],
            'install-path' => '../infocyph/foundation',
            'require' => ['infocyph/webrick' => '^4.0.2'],
        ],
        [
            'name' => 'infocyph/webrick',
            'version' => '4.0.2',
            'source' => ['reference' => 'webrick-ref'],
            'install-path' => '../infocyph/webrick',
        ],
    ];

    $files = [
        'composer.lock' => json_encode(['packages' => $locked, 'packages-dev' => []], JSON_THROW_ON_ERROR),
        'vendor/composer/installed.json' => json_encode(['packages' => $installed], JSON_THROW_ON_ERROR),
        'vendor/infocyph/foundation/src/Routing/RouteFileLoader.php' => routeFileLoaderFixture(),
        'vendor/infocyph/foundation/src/Routing/OAuthRouteRegistrar.php' => oauthRouteRegistrarFixture(),
        'vendor/infocyph/webrick/src/Constants/HttpMethodEnum.php' => httpMethodFixture(),
        'vendor/infocyph/webrick/src/Router/Definition/Registrar.php' => registrarFixture(),
        ...$projectFiles,
    ];

    return TempProject::create(
        composer: [
            'require' => ['infocyph/foundation' => '^2.1.1'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ],
        directories: [
            'app',
            'routes',
            'config',
            'vendor/composer',
            'vendor/infocyph/foundation/src/Routing',
            'vendor/infocyph/webrick/src/Constants',
            'vendor/infocyph/webrick/src/Router/Definition',
        ],
        files: $files,
    );
}

function routeFileLoaderFixture(): string
{
    return <<<'PHP'
<?php
namespace Infocyph\Foundation\Routing;
final readonly class RouteFileLoader
{
    public function __construct(private array $files = ['web.php', 'api.php', 'auth.php']) {}
}
PHP;
}

function httpMethodFixture(): string
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

function registrarFixture(): string
{
    return <<<'PHP'
<?php
namespace Infocyph\Webrick\Router\Definition;
use Infocyph\Webrick\Constants\HttpMethodEnum;
final class Registrar
{
    private function buildResourceSpec(string $param, string $patchAction): array
    {
        return [
            [HttpMethodEnum::GET->value, '', 'index', 'index', true],
            [HttpMethodEnum::GET->value, '/create', 'create', 'create', true],
            [HttpMethodEnum::POST->value, '', 'store', 'store', true],
            [HttpMethodEnum::GET->value, '/{' . $param . '}', 'show', 'show', true],
            [HttpMethodEnum::GET->value, '/{' . $param . '}/edit', 'edit', 'edit', true],
            [HttpMethodEnum::PUT->value, '/{' . $param . '}', 'update', 'update', true],
            [HttpMethodEnum::PATCH->value, '/{' . $param . '}', $patchAction, $patchAction, $patchAction !== 'update'],
            [HttpMethodEnum::DELETE->value, '/{' . $param . '}', 'destroy', 'destroy', true],
        ];
    }
}
PHP;
}

function oauthRouteRegistrarFixture(): string
{
    return <<<'PHP'
<?php
namespace Infocyph\Foundation\Routing;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpHandler;
use Infocyph\Webrick\Router\Definition\Registrar;
final class OAuthRouteRegistrar
{
    public function register(Registrar $router): void
    {
        if (!$this->enabled()) {
            return;
        }
        $router->get(
            '/.well-known/oauth-authorization-server',
            [OAuthHttpHandler::class, 'metadata'],
            'oauth.metadata',
        );
        $router->post($this->path('token'), [OAuthHttpHandler::class, 'token'], 'oauth.token');
    }

    private function enabled(): bool { return true; }
    private function path(string $name): string { return '/oauth/'.$name; }
}
PHP;
}
