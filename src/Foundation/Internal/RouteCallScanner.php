<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation\Internal;

use PhpParser\Node;

/**
 * @phpstan-type RouteEntry array{
 *   method:string,path:?string,name:?string,handler:?string,middleware:list<string>,aliases:list<string>,
 *   options:array<string,mixed>,origin:string,source:string,line:int,status:string,conditional:bool,dynamic_fields:list<string>
 * }
 * @phpstan-type Diagnostic array{code:string,source:?string,line:?int,message:string}
 * @phpstan-type Scope array{prefix:?string,name_prefix:?string,middleware:list<string>,domain:?string,dynamic:list<string>}
 */
final class RouteCallScanner
{
    private const int MAX_DIAGNOSTICS = 100;

    private const int MAX_ROUTES = 2_000;

    private const int MAX_SCAN_DEPTH = 64;

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    private int $expressionDepth = 0;

    /** @var list<RouteEntry> */
    private array $routes = [];

    private int $scanDepth = 0;

    /** @param array<string,true> $verbs */
    public function __construct(
        private readonly array $verbs,
        private readonly RouteValueResolver $values,
        private readonly RouteGroupResolver $groups,
        private readonly ResourceRouteExpander $resources,
    ) {}

    /**
     * @param list<Node\Stmt> $nodes
     * @param array<string,true> $routers
     * @param array<string,true> $presets
     * @param Scope|null $scope
     * @return array{routes:list<RouteEntry>,diagnostics:list<Diagnostic>}
     */
    public function scan(
        array $nodes,
        array $routers,
        array $presets,
        bool $conditional,
        string $origin,
        string $source,
        ?array $scope = null,
    ): array {
        $this->routes = [];
        $this->diagnostics = [];
        $this->scanDepth = 0;
        $this->expressionDepth = 0;
        $this->scanNodes($nodes, $scope ?? $this->groups->emptyScope(), $routers, $presets, $conditional, $origin, $source);

        return ['routes' => $this->routes, 'diagnostics' => $this->diagnostics];
    }

    /** @param array{routes:list<RouteEntry>,diagnostics:list<Diagnostic>} $result */
    private function append(array $result): void
    {
        foreach ($result['routes'] as $route) {
            if (!$this->appendRoute($route)) {
                break;
            }
        }
        foreach ($result['diagnostics'] as $diagnostic) {
            $this->diagnostic($diagnostic['code'], $diagnostic['source'], $diagnostic['line'], $diagnostic['message']);
        }
    }

    /** @param RouteEntry $route */
    private function appendRoute(array $route): bool
    {
        if (count($this->routes) >= self::MAX_ROUTES) {
            $this->limitDiagnostic();

            return false;
        }

        $this->routes[] = $route;

        return true;
    }

    private function callMethod(Node\Expr $expr): ?string
    {
        return ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\StaticCall) && $expr->name instanceof Node\Identifier
            ? $expr->name->toString()
            : null;
    }

    /**
     * @param array<string,true> $routers
     * @param array<string,true> $presets
     * @return array{kind:string,method:string}|null
     */
    private function descriptor(Node\Expr $expr, array $routers, array $presets): ?array
    {
        $method = $this->callMethod($expr);
        if ($method === null) {
            return null;
        }

        $lower = strtolower($method);
        if ($this->routerCall($expr, $routers)) {
            if (isset($this->verbs[$lower])) {
                return ['kind' => 'route', 'method' => strtoupper($method)];
            }
            if ($lower === 'group' || $lower === 'resource') {
                return ['kind' => $lower, 'method' => strtoupper($method)];
            }
        }

        if ($lower === 'group' && $expr instanceof Node\Expr\MethodCall && $this->variableNamed($expr->var, $presets)) {
            return ['kind' => 'preset_group', 'method' => 'GROUP'];
        }

        return null;
    }

    private function diagnostic(string $code, ?string $source, ?int $line, string $message): void
    {
        if (count($this->diagnostics) < self::MAX_DIAGNOSTICS) {
            $this->diagnostics[] = [
                'code' => $code,
                'source' => $source,
                'line' => $line,
                'message' => $message,
            ];

            return;
        }

        $this->diagnostics[self::MAX_DIAGNOSTICS - 1] = [
            'code' => 'diagnostics_truncated',
            'source' => null,
            'line' => null,
            'message' => sprintf('Route-call diagnostics are limited to %d entries.', self::MAX_DIAGNOSTICS),
        ];
    }

    /**
     * @param Scope $scope
     * @param array<string,true> $routers
     * @param array<string,true> $presets
     */
    private function group(
        Node\Expr $expr,
        array $scope,
        array $routers,
        array $presets,
        bool $conditional,
        string $origin,
        string $source,
        bool $preset,
    ): void {
        $args = $this->values->callArgs($expr);
        $group = $preset ? $this->groups->preset($args) : $this->groups->router($args);
        $callback = $group['callback'];
        if (!$callback instanceof Node\Expr\Closure) {
            $this->diagnostic('dynamic_unresolved', $source, $expr->getStartLine(), 'Route group callback is not a statically inspectable closure.');

            return;
        }

        $childRouters = $routers;
        $first = $callback->params[0] ?? null;
        if ($first instanceof Node\Param && $first->var instanceof Node\Expr\Variable && is_string($first->var->name)) {
            $childRouters[$first->var->name] = true;
        }

        $this->scanNodes(
            $callback->stmts,
            $this->groups->child($scope, $group),
            $childRouters,
            $presets,
            $conditional,
            $origin,
            $source,
        );
    }

    private function limitDiagnostic(): void
    {
        foreach ($this->diagnostics as $item) {
            if ($item['code'] === 'route_limit_exceeded') {
                return;
            }
        }
        $this->diagnostic('route_limit_exceeded', null, null, sprintf('Route-call inspection is limited to %d routes.', self::MAX_ROUTES));
    }

    /** @param list<string> $aliases @param list<string> $dynamic @return list<string> */
    private function prefixAliases(array $aliases, ?string $prefix, array &$dynamic): array
    {
        if ($aliases === [] || $prefix === '') {
            return $aliases;
        }
        if ($prefix === null) {
            $dynamic[] = 'aliases';

            return [];
        }

        return array_values(array_map(static fn(string $alias): string => $prefix . $alias, $aliases));
    }

    /** @param Scope $scope @return RouteEntry */
    private function route(
        Node\Expr $expr,
        string $method,
        array $scope,
        bool $conditional,
        string $origin,
        string $source,
    ): array {
        $args = $this->values->callArgs($expr);
        $pathExpr = $this->values->arg($args, 0, 'path');
        $handlerExpr = $this->values->arg($args, 1, 'handler');
        $optionsExpr = $this->values->arg($args, 2, 'nameOrOpts');
        $path = $pathExpr instanceof Node\Expr ? $this->values->stringValue($pathExpr) : null;
        $handler = $handlerExpr instanceof Node\Expr ? $this->values->handler($handlerExpr) : null;
        [$name, $middleware, $aliases, $options, $optionDynamic] = $this->values->routeOptions($optionsExpr);
        $dynamic = $scope['dynamic'];

        $path === null && $dynamic[] = 'path';
        $handler === null && $dynamic[] = 'handler';
        $optionDynamic && $dynamic[] = 'options';
        $fullPath = $path !== null && $scope['prefix'] !== null ? $this->values->joinPath($scope['prefix'], $path) : null;
        $scope['prefix'] === null && $dynamic[] = 'path';

        $fullName = $name;
        if ($name !== null && $scope['name_prefix'] !== null) {
            $fullName = $scope['name_prefix'] . $name;
        } elseif ($name !== null) {
            $fullName = null;
            $dynamic[] = 'name';
        }

        $aliases = $this->prefixAliases($aliases, $scope['name_prefix'], $dynamic);
        if ($scope['domain'] !== null) {
            $options['domain'] = $scope['domain'];
        }
        $dynamic = $this->values->uniqueStrings($dynamic);

        return [
            'method' => strtoupper($method),
            'path' => $fullPath,
            'name' => $fullName,
            'handler' => $handler,
            'middleware' => array_values(array_unique([...$scope['middleware'], ...$middleware])),
            'aliases' => $aliases,
            'options' => $options,
            'origin' => $origin,
            'source' => $source,
            'line' => $expr->getStartLine(),
            'status' => $dynamic === [] ? 'resolved' : 'dynamic',
            'conditional' => $conditional,
            'dynamic_fields' => $dynamic,
        ];
    }

    /** @param array<string,true> $routers */
    private function routerCall(Node\Expr $expr, array $routers): bool
    {
        if ($expr instanceof Node\Expr\MethodCall) {
            return $this->variableNamed($expr->var, $routers);
        }
        if (!$expr instanceof Node\Expr\StaticCall || !$expr->class instanceof Node\Name) {
            return false;
        }
        $class = $this->values->resolvedName($expr->class);

        return $class === 'Infocyph\\Webrick\\Router\\Facade\\Router' || $class === 'Router';
    }

    /**
     * @param Scope $scope
     * @param array<string,true> $routers
     * @param array<string,true> $presets
     */
    private function scanCall(
        Node\Expr $expr,
        array $scope,
        array $routers,
        array $presets,
        bool $conditional,
        string $origin,
        string $source,
    ): bool {
        $call = $this->descriptor($expr, $routers, $presets);
        if ($call === null) {
            return false;
        }

        if ($call['kind'] === 'route') {
            $this->appendRoute($this->route($expr, $call['method'], $scope, $conditional, $origin, $source));

            return true;
        }
        if ($call['kind'] === 'resource') {
            $result = $this->resources->expand($expr, $scope, $conditional, $origin, $source);
            $this->append($result);

            return true;
        }
        if ($call['kind'] === 'group' || $call['kind'] === 'preset_group') {
            $this->group($expr, $scope, $routers, $presets, $conditional, $origin, $source, $call['kind'] === 'preset_group');

            return true;
        }

        return false;
    }

    /**
     * @param Scope $scope
     * @param array<string,true> $routers
     * @param array<string,true> $presets
     */
    private function scanExpressionTree(
        Node\Expr $expr,
        array $scope,
        array $routers,
        array $presets,
        bool $conditional,
        string $origin,
        string $source,
    ): void {
        if (++$this->expressionDepth > self::MAX_SCAN_DEPTH) {
            --$this->expressionDepth;
            $this->diagnostic('inspection_limit_exceeded', $source, $expr->getStartLine(), sprintf('Route expression nesting exceeds %d levels.', self::MAX_SCAN_DEPTH));

            return;
        }

        try {
            if ($expr instanceof Node\Expr\Closure || $expr instanceof Node\Expr\ArrowFunction) {
                return;
            }
            if ($this->scanCall($expr, $scope, $routers, $presets, $conditional, $origin, $source)) {
                return;
            }

            foreach ($expr->getSubNodeNames() as $name) {
                $value = $expr->{$name};
                if ($value instanceof Node\Expr) {
                    $this->scanExpressionTree($value, $scope, $routers, $presets, $conditional, $origin, $source);

                    continue;
                }
                if (!is_array($value)) {
                    continue;
                }
                foreach ($value as $item) {
                    if ($item instanceof Node\Expr) {
                        $this->scanExpressionTree($item, $scope, $routers, $presets, $conditional, $origin, $source);
                    } elseif ($item instanceof Node\Arg) {
                        $this->scanExpressionTree($item->value, $scope, $routers, $presets, $conditional, $origin, $source);
                    }
                }
            }
        } finally {
            --$this->expressionDepth;
        }
    }

    /** @param Scope $scope @param array<string,true> $routers @param array<string,true> $presets */
    private function scanIf(Node\Stmt\If_ $if, array $scope, array $routers, array $presets, string $origin, string $source): void
    {
        $this->scanNodes($if->stmts, $scope, $routers, $presets, true, $origin, $source);
        foreach ($if->elseifs as $elseif) {
            $this->scanNodes($elseif->stmts, $scope, $routers, $presets, true, $origin, $source);
        }
        if ($if->else !== null) {
            $this->scanNodes($if->else->stmts, $scope, $routers, $presets, true, $origin, $source);
        }
    }

    /** @param Scope $scope @param array<string,true> $routers @param array<string,true> $presets */
    private function scanNode(
        Node $node,
        array $scope,
        array $routers,
        array $presets,
        bool $conditional,
        string $origin,
        string $source,
    ): void {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->scanNodes($node->stmts, $scope, $routers, $presets, $conditional, $origin, $source);

            return;
        }
        if ($node instanceof Node\Stmt\Expression) {
            if (!$this->scanCall($node->expr, $scope, $routers, $presets, $conditional, $origin, $source)) {
                $this->scanExpressionTree($node->expr, $scope, $routers, $presets, true, $origin, $source);
            }

            return;
        }
        if ($node instanceof Node\Stmt\If_) {
            $this->scanIf($node, $scope, $routers, $presets, $origin, $source);

            return;
        }
        if ($node instanceof Node\Stmt\Foreach_ || $node instanceof Node\Stmt\For_ || $node instanceof Node\Stmt\While_ || $node instanceof Node\Stmt\Do_) {
            $this->scanNodes($node->stmts, $scope, $routers, $presets, true, $origin, $source);

            return;
        }
        if ($node instanceof Node\Stmt\TryCatch) {
            $this->scanTry($node, $scope, $routers, $presets, $origin, $source);

            return;
        }
        if ($node instanceof Node\Stmt\Switch_) {
            foreach ($node->cases as $case) {
                $this->scanNodes($case->stmts, $scope, $routers, $presets, true, $origin, $source);
            }
        }
    }

    /**
     * @param list<Node\Stmt> $nodes
     * @param Scope $scope
     * @param array<string,true> $routers
     * @param array<string,true> $presets
     */
    private function scanNodes(
        array $nodes,
        array $scope,
        array $routers,
        array $presets,
        bool $conditional,
        string $origin,
        string $source,
    ): void {
        if (++$this->scanDepth > self::MAX_SCAN_DEPTH) {
            --$this->scanDepth;
            $this->diagnostic('inspection_limit_exceeded', $source, null, sprintf('Route statement nesting exceeds %d levels.', self::MAX_SCAN_DEPTH));

            return;
        }

        try {
            foreach ($nodes as $node) {
                $this->scanNode($node, $scope, $routers, $presets, $conditional, $origin, $source);
                if (count($this->routes) >= self::MAX_ROUTES) {
                    $this->limitDiagnostic();

                    return;
                }
            }
        } finally {
            --$this->scanDepth;
        }
    }

    /** @param Scope $scope @param array<string,true> $routers @param array<string,true> $presets */
    private function scanTry(Node\Stmt\TryCatch $try, array $scope, array $routers, array $presets, string $origin, string $source): void
    {
        $this->scanNodes($try->stmts, $scope, $routers, $presets, true, $origin, $source);
        foreach ($try->catches as $catch) {
            $this->scanNodes($catch->stmts, $scope, $routers, $presets, true, $origin, $source);
        }
        if ($try->finally !== null) {
            $this->scanNodes($try->finally->stmts, $scope, $routers, $presets, true, $origin, $source);
        }
    }

    /** @param array<string,true> $names */
    private function variableNamed(Node\Expr $expr, array $names): bool
    {
        return $expr instanceof Node\Expr\Variable && is_string($expr->name) && isset($names[$expr->name]);
    }
}
