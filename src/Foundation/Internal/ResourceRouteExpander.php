<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation\Internal;

use Infocyph\FoundationMcp\Foundation\InstalledRoutingContract;
use PhpParser\Node;
use RuntimeException;

/**
 * @phpstan-type RouteEntry array{
 *   method:string,path:?string,name:?string,handler:?string,middleware:list<string>,aliases:list<string>,
 *   options:array<string,mixed>,origin:string,source:string,line:int,status:string,conditional:bool,dynamic_fields:list<string>
 * }
 * @phpstan-type Diagnostic array{code:string,source:?string,line:?int,message:string}
 * @phpstan-type Scope array{prefix:?string,name_prefix:?string,middleware:list<string>,domain:?string,dynamic:list<string>}
 */
final readonly class ResourceRouteExpander
{
    public function __construct(
        private InstalledRoutingContract $contract,
        private RouteValueResolver $values,
    ) {}

    /**
     * @param Scope $scope
     * @return array{routes:list<RouteEntry>,diagnostics:list<Diagnostic>}
     */
    public function expand(
        Node\Expr $expr,
        array $scope,
        bool $conditional,
        string $origin,
        string $source,
    ): array {
        $args = $this->values->callArgs($expr);
        $nameExpr = $this->values->arg($args, 0, 'name');
        $prefixExpr = $this->values->arg($args, 1, 'prefix');
        $controllerExpr = $this->values->arg($args, 2, 'ctrl') ?? $this->values->arg($args, 2, 'controller');
        $optionsExpr = $this->values->arg($args, 3, 'opts');
        $name = $nameExpr instanceof Node\Expr ? $this->values->stringValue($nameExpr) : null;
        $prefix = $prefixExpr instanceof Node\Expr ? $this->values->stringValue($prefixExpr) : null;
        $controller = $controllerExpr instanceof Node\Expr ? $this->values->classString($controllerExpr) : null;
        $options = $optionsExpr instanceof Node\Expr ? $this->values->literalArrayValue($optionsExpr) : [];

        if ($name === null || $prefix === null || $controller === null || !is_array($options)) {
            return ['routes' => [$this->dynamic($expr, $scope, $conditional, $origin, $source)], 'diagnostics' => []];
        }

        return $this->expandResolved($expr, $scope, $conditional, $origin, $source, $name, $prefix, $controller, $options);
    }

    /**
     * @param Scope $scope
     * @param array<string,mixed> $options
     * @return array{routes:list<RouteEntry>,diagnostics:list<Diagnostic>}
     */
    private function expandResolved(
        Node\Expr $expr,
        array $scope,
        bool $conditional,
        string $origin,
        string $source,
        string $name,
        string $prefix,
        string $controller,
        array $options,
    ): array {
        $param = is_string($options['param'] ?? null) ? $options['param'] : 'id';
        $patchAction = is_string($options['patch_action'] ?? null) ? $options['patch_action'] : 'update';
        $only = $this->values->stringList($options['only'] ?? null);
        $except = $this->values->stringList($options['except'] ?? null);
        $names = $this->values->stringMap($options['names'] ?? null);
        $middleware = $this->values->stringList($options['middleware'] ?? null) ?? [];

        try {
            $spec = $this->contract->resourceSpec($param, $patchAction);
        } catch (RuntimeException $error) {
            return [
                'routes' => [$this->dynamic($expr, $scope, $conditional, $origin, $source)],
                'diagnostics' => [[
                    'code' => 'routing_contract_invalid',
                    'source' => $source,
                    'line' => $expr->getStartLine(),
                    'message' => $error->getMessage(),
                ]],
            ];
        }

        $routes = [];
        foreach ($spec as $row) {
            if (!$this->include($row['key'], $only, $except)) {
                continue;
            }
            $routes[] = $this->route(
                $row,
                $scope,
                $middleware,
                $name,
                $prefix,
                $controller,
                $names,
                $expr->getStartLine(),
                $conditional,
                $origin,
                $source,
            );
        }

        return ['routes' => $routes, 'diagnostics' => []];
    }

    /**
     * @param array{method:string,suffix:string,action:string,key:string,nameable:bool} $row
     * @param Scope $scope
     * @param list<string> $middleware
     * @param array<string,string> $names
     * @return RouteEntry
     */
    private function route(
        array $row,
        array $scope,
        array $middleware,
        string $name,
        string $prefix,
        string $controller,
        array $names,
        int $line,
        bool $conditional,
        string $origin,
        string $source,
    ): array {
        $dynamic = $scope['dynamic'];
        $path = $scope['prefix'] === null
            ? null
            : $this->values->joinPath($scope['prefix'], rtrim($prefix, '/').$row['suffix']);
        $path === null && $dynamic[] = 'path';

        $routeName = $row['nameable'] ? ($names[$row['key']] ?? $name.'.'.$row['key']) : null;
        if ($routeName !== null && $scope['name_prefix'] !== null) {
            $routeName = $scope['name_prefix'].$routeName;
        } elseif ($routeName !== null) {
            $routeName = null;
            $dynamic[] = 'name';
        }

        $dynamic = $this->values->uniqueStrings($dynamic);

        return [
            'method' => $row['method'],
            'path' => $path,
            'name' => $routeName,
            'handler' => $controller.'::'.$row['action'],
            'middleware' => array_values(array_unique([...$scope['middleware'], ...$middleware])),
            'aliases' => [],
            'options' => ['resource' => $name, 'resource_key' => $row['key']],
            'origin' => $origin,
            'source' => $source,
            'line' => $line,
            'status' => $dynamic === [] ? 'resolved' : 'dynamic',
            'conditional' => $conditional,
            'dynamic_fields' => $dynamic,
        ];
    }

    /** @param Scope $scope @return RouteEntry */
    private function dynamic(Node\Expr $expr, array $scope, bool $conditional, string $origin, string $source): array
    {
        return [
            'method' => 'RESOURCE',
            'path' => null,
            'name' => null,
            'handler' => null,
            'middleware' => $scope['middleware'],
            'aliases' => [],
            'options' => ['resource_registration' => true],
            'origin' => $origin,
            'source' => $source,
            'line' => $expr->getStartLine(),
            'status' => 'dynamic',
            'conditional' => $conditional,
            'dynamic_fields' => $this->values->uniqueStrings([...$scope['dynamic'], 'resource']),
        ];
    }

    /** @param list<string>|null $only @param list<string>|null $except */
    private function include(string $key, ?array $only, ?array $except): bool
    {
        return ($only === null || in_array($key, $only, true))
            && ($except === null || !in_array($key, $except, true));
    }
}
