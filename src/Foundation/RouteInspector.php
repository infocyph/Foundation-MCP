<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation;

use Infocyph\FoundationMcp\Analysis\SourceFileFinder;
use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Foundation\Internal\AttributeRouteScanner;
use Infocyph\FoundationMcp\Foundation\Internal\ResourceRouteExpander;
use Infocyph\FoundationMcp\Foundation\Internal\RouteCallScanner;
use Infocyph\FoundationMcp\Foundation\Internal\RouteGroupResolver;
use Infocyph\FoundationMcp\Foundation\Internal\RouteValueResolver;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Project\SourceRoots;
use Infocyph\FoundationMcp\Security\PathPolicy;
use Infocyph\FoundationMcp\Security\SecretPolicy;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;

/**
 * Static, non-bootstrapping Foundation/Webrick route inspection.
 *
 * @phpstan-type RouteEntry array{
 *   method:string,path:?string,name:?string,handler:?string,middleware:list<string>,aliases:list<string>,
 *   options:array<string,mixed>,origin:string,source:string,line:int,status:string,conditional:bool,dynamic_fields:list<string>
 * }
 * @phpstan-type Diagnostic array{code:string,source:?string,line:?int,message:string}
 */
final class RouteInspector
{
    private const int MAX_ATTRIBUTE_FILES = 2_500;

    private const int MAX_DIAGNOSTICS = 100;

    private const int MAX_ROUTES = 2_000;

    private const int MAX_SOURCE_BYTES = 1_048_576;

    private readonly InstalledRoutingContract $contract;

    private readonly SourceFileFinder $finder;

    private readonly Parser $parser;

    private readonly PathPolicy $paths;

    private readonly SecretPolicy $secrets;

    private readonly RouteValueResolver $values;

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    /** @var list<RouteEntry> */
    private array $routes = [];

    /** @var array<string,true> */
    private array $verbs = [];

    public function __construct(
        private readonly Project $project,
        private readonly ComposerInspector $composer,
        ?Parser $parser = null,
        ?InstalledRoutingContract $contract = null,
        ?SourceFileFinder $finder = null,
    ) {
        $this->parser = $parser ?? new ParserFactory()->createForNewestSupportedVersion();
        $this->paths = new PathPolicy($project->root);
        $this->secrets = new SecretPolicy();
        $this->contract = $contract ?? new InstalledRoutingContract($project, $composer, $this->parser);
        $this->finder = $finder ?? new SourceFileFinder($project, $composer);
        $this->values = new RouteValueResolver();
    }

    /**
     * @return array{route_files:list<string>,http_methods:list<string>,routes:list<RouteEntry>,diagnostics:list<Diagnostic>}
     */
    public function inspect(): array
    {
        $this->routes = [];
        $this->diagnostics = [];

        try {
            $routeFiles = $this->contract->routeFiles();
            $methods = array_values($this->contract->httpMethods());
        } catch (RuntimeException $error) {
            $this->diagnostic('routing_contract_invalid', null, null, $error->getMessage());

            return ['route_files' => [], 'http_methods' => [], 'routes' => [], 'diagnostics' => $this->diagnostics];
        }

        sort($methods, SORT_STRING);
        $this->verbs = array_fill_keys(array_map(strtolower(...), $methods), true);

        foreach ($routeFiles as $file) {
            $this->inspectProjectRouteFile('routes/' . $file);
        }
        $this->inspectFoundationOAuthRoutes();
        $this->inspectAttributeRoutes();
        $this->finalize();

        return [
            'route_files' => $routeFiles,
            'http_methods' => $methods,
            'routes' => $this->routes,
            'diagnostics' => $this->diagnostics,
        ];
    }

    /** @param array{routes:list<RouteEntry>,diagnostics:list<Diagnostic>} $result */
    private function append(array $result): void
    {
        $this->appendRoutes($result['routes']);
        foreach ($result['diagnostics'] as $diagnostic) {
            $this->diagnostic($diagnostic['code'], $diagnostic['source'], $diagnostic['line'], $diagnostic['message']);
        }
    }

    /** @param list<RouteEntry> $routes */
    private function appendRoutes(array $routes): void
    {
        foreach ($routes as $route) {
            if (count($this->routes) >= self::MAX_ROUTES) {
                $this->routeLimitDiagnostic();

                return;
            }
            $this->routes[] = $route;
        }
    }

    /** @param list<string> $applicationRoots */
    private function applicationPath(string $relative, array $applicationRoots): bool
    {
        try {
            $path = $this->comparisonPath($this->paths->projectFile($relative));
        } catch (RuntimeException) {
            return false;
        }

        foreach ($applicationRoots as $root) {
            $root = $this->comparisonPath($root);
            if ($path === $root || str_starts_with($path, $root . '/')) {
                return true;
            }
        }

        return false;
    }

    private function attributeRoutingEnabled(): ?bool
    {
        $relative = 'config/router.php';
        if (!is_file($this->project->root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'router.php')) {
            return null;
        }

        try {
            $nodes = $this->parse($this->paths->projectFile($relative), $relative);
            foreach ($nodes ?? [] as $node) {
                if (!$node instanceof Node\Stmt\Return_ || !$node->expr instanceof Node\Expr) {
                    continue;
                }
                $value = $this->values->literalArrayValue($node->expr);
                $attributes = is_array($value) ? ($value['attributes'] ?? null) : null;

                return is_array($attributes) && is_bool($attributes['enabled'] ?? null)
                    ? $attributes['enabled']
                    : null;
            }
        } catch (RuntimeException) {
        }

        return null;
    }

    private function calls(): RouteCallScanner
    {
        return new RouteCallScanner(
            $this->verbs,
            $this->values,
            new RouteGroupResolver($this->values),
            new ResourceRouteExpander($this->contract, $this->values),
        );
    }

    /**
     * @param list<Node\Stmt> $nodes
     * @return list<Node\Stmt\ClassMethod>
     */
    private function classMethods(array $nodes, string $name): array
    {
        $methods = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                array_push($methods, ...$this->classMethods($node->stmts, $name));

                continue;
            }
            if ($node instanceof Node\Stmt\Class_) {
                $method = $node->getMethod($name);
                $method instanceof Node\Stmt\ClassMethod && $methods[] = $method;
            }
        }

        return $methods;
    }

    private function comparisonPath(string $path): string
    {
        $path = str_replace('\\', '/', rtrim($path, '/\\'));

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    private function diagnostic(string $code, ?string $source, ?int $line, string $message): void
    {
        if (count($this->diagnostics) < self::MAX_DIAGNOSTICS) {
            $this->diagnostics[] = compact('code', 'source', 'line', 'message');
        }
    }

    private function finalize(): void
    {
        usort($this->routes, static fn(array $left, array $right): int
            => [$left['source'], $left['line'], $left['method'], $left['path'] ?? '', $left['name'] ?? '']
            <=> [$right['source'], $right['line'], $right['method'], $right['path'] ?? '', $right['name'] ?? '']);
        usort($this->diagnostics, static fn(array $left, array $right): int
            => [$left['source'] ?? '', $left['line'] ?? 0, $left['code'], $left['message']]
            <=> [$right['source'] ?? '', $right['line'] ?? 0, $right['code'], $right['message']]);
    }

    private function inspectAttributeRoutes(): void
    {
        $enabled = $this->attributeRoutingEnabled();
        if ($enabled === false) {
            return;
        }

        $roots = SourceRoots::discover($this->project)->application;
        $scanner = new AttributeRouteScanner($this->values, $this->verbs);
        $count = 0;

        foreach (array_keys($this->finder->project()) as $relative) {
            if (!$this->applicationPath($relative, $roots)) {
                continue;
            }
            if (++$count > self::MAX_ATTRIBUTE_FILES) {
                $this->diagnostic('output_limit_exceeded', null, null, sprintf(
                    'Attribute route inspection stopped after %d application PHP files.',
                    self::MAX_ATTRIBUTE_FILES,
                ));

                break;
            }

            try {
                $nodes = $this->parse($this->paths->projectFile($relative), $relative);
                if ($nodes !== null) {
                    $this->appendRoutes($scanner->scan($nodes, $relative, $enabled !== true));
                }
            } catch (RuntimeException $error) {
                $this->diagnostic('route_source_invalid', $relative, null, $error->getMessage());
            }
        }
    }

    private function inspectFoundationOAuthRoutes(): void
    {
        $foundation = $this->composer->foundation();
        if ($foundation?->installPath === null) {
            return;
        }

        $relative = 'src/Routing/OAuthRouteRegistrar.php';
        $source = 'infocyph/foundation:' . $relative;

        try {
            $paths = new PathPolicy($this->project->root, ['infocyph/foundation' => $foundation->installPath]);
            $nodes = $this->parse($paths->packageFile('infocyph/foundation', $relative), $source);
            if ($nodes === null) {
                return;
            }

            foreach ($this->classMethods($nodes, 'register') as $method) {
                $this->append($this->calls()->scan(
                    $method->stmts ?? [],
                    $this->registrarParameters($method),
                    [],
                    true,
                    'foundation',
                    $source,
                ));
            }
        } catch (RuntimeException $error) {
            $this->diagnostic('foundation_route_source_invalid', $source, null, $error->getMessage());
        }
    }

    private function inspectProjectRouteFile(string $relative): void
    {
        $candidate = $this->project->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($candidate)) {
            return;
        }

        try {
            $nodes = $this->parse($this->paths->projectFile($relative), $relative);
            if ($nodes !== null) {
                $this->append($this->calls()->scan($nodes, ['router' => true], ['presets' => true], false, 'project_file', $relative));
            }
        } catch (RuntimeException $error) {
            $this->diagnostic('route_source_invalid', $relative, null, $error->getMessage());
        }
    }

    /** @return list<Node\Stmt>|null */
    private function parse(string $path, string $source): ?array
    {
        $this->secrets->assertAllowed($source);

        try {
            $nodes = $this->parser->parse($this->read($path));
        } catch (Error $error) {
            $this->diagnostic('parse_error', $source, $error->getStartLine() ?: null, $error->getRawMessage());

            return null;
        }
        if (!is_array($nodes)) {
            $this->diagnostic('parse_error', $source, null, 'PHP parser returned no syntax tree.');

            return null;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]));

        try {
            /** @var list<Node\Stmt> $resolved */
            $resolved = $traverser->traverse($nodes);

            return $resolved;
        } catch (Error $error) {
            $this->diagnostic('parse_error', $source, $error->getStartLine() ?: null, $error->getRawMessage());

            return null;
        }
    }

    private function read(string $path): string
    {
        $size = filesize($path);
        if ($size !== false && $size > self::MAX_SOURCE_BYTES) {
            throw new RuntimeException('Route source exceeds the 1 MiB inspection limit.');
        }
        $source = file_get_contents($path);
        if ($source === false || strlen($source) > self::MAX_SOURCE_BYTES || str_contains($source, "\0")) {
            throw new RuntimeException('Route source could not be read safely.');
        }

        return $source;
    }

    /** @return array<string,true> */
    private function registrarParameters(Node\Stmt\ClassMethod $method): array
    {
        $routers = [];
        foreach ($method->params as $param) {
            if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name) || !$param->type instanceof Node\Name) {
                continue;
            }
            if (str_ends_with($this->values->resolvedName($param->type), '\\Registrar')) {
                $routers[$param->var->name] = true;
            }
        }

        return $routers;
    }

    private function routeLimitDiagnostic(): void
    {
        foreach ($this->diagnostics as $item) {
            if ($item['code'] === 'output_limit_exceeded') {
                return;
            }
        }
        $this->diagnostic('output_limit_exceeded', null, null, sprintf('Route inspection is limited to %d routes.', self::MAX_ROUTES));
    }
}
