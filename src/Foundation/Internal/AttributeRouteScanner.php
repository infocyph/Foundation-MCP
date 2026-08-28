<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation\Internal;

use PhpParser\Node;

/**
 * @phpstan-type RouteEntry array{
 *   method:string,path:?string,name:?string,handler:?string,middleware:list<string>,aliases:list<string>,
 *   options:array<string,mixed>,origin:string,source:string,line:int,status:string,conditional:bool,dynamic_fields:list<string>
 * }
 */
final readonly class AttributeRouteScanner
{
    /** @param array<string,true> $verbs */
    public function __construct(
        private RouteValueResolver $values,
        private array $verbs,
    ) {}

    /**
     * @param list<Node\Stmt> $nodes
     * @return list<RouteEntry>
     */
    public function scan(array $nodes, string $source, bool $conditional): array
    {
        $routes = [];
        $this->scanNodes($nodes, $source, $conditional, $routes);

        return $routes;
    }

    /**
     * @param list<string> $middleware
     * @param list<string> $classDynamic
     * @return list<RouteEntry>
     */
    private function attributeRoutes(
        Node\Attribute $attribute,
        string $class,
        string $method,
        ?string $prefix,
        ?string $namePrefix,
        ?string $domain,
        array $middleware,
        array $classDynamic,
        bool $conditional,
        string $source,
    ): array {
        $args = $this->values->attributeArgs($attribute);
        $methodsExpr = $this->values->arg($args, 0, 'method');
        $pathExpr = $this->values->arg($args, 1, 'path');
        $nameExpr = $this->values->arg($args, 2, 'name');
        $routeMiddlewareExpr = $this->values->arg($args, 3, 'middleware');
        $methods = $methodsExpr instanceof Node\Expr ? $this->values->methodList($methodsExpr) : [];
        $path = $pathExpr instanceof Node\Expr ? $this->values->stringValue($pathExpr) : null;
        $name = $nameExpr instanceof Node\Expr ? $this->values->nullableStringExpr($nameExpr) : null;
        $declaredRouteMiddleware = $routeMiddlewareExpr instanceof Node\Expr
            ? $this->values->middlewareValue($routeMiddlewareExpr)
            : [];
        $dynamic = $classDynamic;

        if ($methods === []) {
            $methods = ['DYNAMIC'];
            $dynamic[] = 'method';
        }
        if ($path === null || $prefix === null) {
            $dynamic[] = 'path';
        }
        if ($name !== null && $namePrefix === null) {
            $dynamic[] = 'name';
        }

        $fullPath = $path !== null && $prefix !== null ? $this->values->joinPath($prefix, $path) : null;
        $fullName = $name !== null && $namePrefix !== null ? $namePrefix . $name : $name;
        $options = ['attribute' => true];
        if ($declaredRouteMiddleware !== [] && $declaredRouteMiddleware !== null) {
            // Current Webrick AttributeRouteLoader does not apply Route::$middleware;
            // preserve it as declared metadata without misreporting it as effective middleware.
            $options['declared_route_middleware'] = $declaredRouteMiddleware;
        }
        if ($domain !== null) {
            $options['domain'] = $domain;
        }
        if ($conditional) {
            $options['activation'] = 'router.attributes.enabled unknown';
        }

        return $this->emitMethods(
            $methods,
            $fullPath,
            $fullName,
            $class . '::' . $method,
            array_values(array_unique($middleware)),
            $options,
            $dynamic,
            $conditional,
            $source,
            $attribute->getStartLine(),
        );
    }

    /**
     * @return array{0:?string,1:?string,2:list<string>,3:?string,4:list<string>}
     */
    private function classContext(Node\Stmt\Class_ $class): array
    {
        [$classMiddleware, $classMiddlewareDynamic] = $this->middlewareAttributes($class->attrGroups);
        $group = $this->firstAttribute($class->attrGroups, 'Infocyph\\Webrick\\Router\\Definition\\Attribute\\Group');
        if (!$group instanceof Node\Attribute) {
            return ['', null, $classMiddleware, '', $classMiddlewareDynamic ? ['middleware'] : []];
        }

        [$prefix, $domain, $groupMiddleware, $name, $groupDynamic] = $this->groupContext($group);
        $dynamic = $classMiddlewareDynamic ? [...$groupDynamic, 'middleware'] : $groupDynamic;

        return [
            $prefix,
            $domain,
            array_values(array_unique([...$groupMiddleware, ...$classMiddleware])),
            $name,
            $this->values->uniqueStrings($dynamic),
        ];
    }

    /**
     * @param list<string> $methods
     * @param list<string> $middleware
     * @param array<string,mixed> $options
     * @param list<string> $dynamic
     * @return list<RouteEntry>
     */
    private function emitMethods(
        array $methods,
        ?string $path,
        ?string $name,
        string $handler,
        array $middleware,
        array $options,
        array $dynamic,
        bool $conditional,
        string $source,
        int $line,
    ): array {
        $routes = [];
        foreach ($methods as $verb) {
            $method = strtoupper($verb);
            $methodDynamic = $dynamic;
            if ($method !== 'DYNAMIC' && !isset($this->verbs[strtolower($method)])) {
                continue;
            }
            $methodDynamic = $this->values->uniqueStrings($methodDynamic);

            $routes[] = [
                'method' => $method,
                'path' => $path,
                'name' => $name,
                'handler' => $handler,
                'middleware' => $middleware,
                'aliases' => [],
                'options' => $options,
                'origin' => 'project_attribute',
                'source' => $source,
                'line' => $line,
                'status' => $methodDynamic === [] ? 'resolved' : 'dynamic',
                'conditional' => $conditional,
                'dynamic_fields' => $methodDynamic,
            ];
        }

        return $routes;
    }

    /**
     * @param list<Node\AttributeGroup> $groups
     */
    private function firstAttribute(array $groups, string $name): ?Node\Attribute
    {
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->values->attributeName($attribute) === $name) {
                    return $attribute;
                }
            }
        }

        return null;
    }

    /**
     * @return array{0:?string,1:?string,2:list<string>,3:?string,4:list<string>}
     */
    private function groupContext(Node\Attribute $attribute): array
    {
        $args = $this->values->attributeArgs($attribute);
        $prefixExpr = $this->values->arg($args, 0, 'prefix');
        $domainExpr = $this->values->arg($args, 1, 'domain');
        $middlewareExpr = $this->values->arg($args, 2, 'middleware');
        $nameExpr = $this->values->arg($args, 3, 'name');
        $prefix = $prefixExpr instanceof Node\Expr ? $this->values->nullableStringExpr($prefixExpr) : '';
        $domain = $domainExpr instanceof Node\Expr ? $this->values->nullableStringExpr($domainExpr) : null;
        $name = $nameExpr instanceof Node\Expr ? $this->values->nullableStringExpr($nameExpr) : '';
        $middleware = $middlewareExpr instanceof Node\Expr ? $this->values->middlewareValue($middlewareExpr) : [];
        $dynamic = [];

        $prefixExpr instanceof Node\Expr && $prefix === null && $dynamic[] = 'path';
        $nameExpr instanceof Node\Expr && $name === null && $dynamic[] = 'name';
        $middleware === null && $dynamic[] = 'middleware';
        if ($domainExpr instanceof Node\Expr && !$this->nullLiteral($domainExpr) && $domain === null) {
            $dynamic[] = 'domain';
        }

        return [$prefix, $domain, $middleware ?? [], $name, $dynamic];
    }

    /**
     * @param list<Node\AttributeGroup> $groups
     * @return array{0:list<string>,1:bool}
     */
    private function middlewareAttributes(array $groups): array
    {
        $middleware = [];
        $dynamic = false;
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->values->attributeName($attribute) !== 'Infocyph\\Webrick\\Router\\Definition\\Attribute\\Middleware') {
                    continue;
                }

                $args = $this->values->attributeArgs($attribute);
                $value = $this->values->arg($args, 0, 'stack');
                $resolved = $value instanceof Node\Expr ? $this->values->middlewareValue($value) : null;
                if ($resolved === null) {
                    $dynamic = true;

                    continue;
                }
                array_push($middleware, ...$resolved);
            }
        }

        return [array_values(array_unique($middleware)), $dynamic];
    }

    private function nullLiteral(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\ConstFetch && strtolower($expr->name->toString()) === 'null';
    }

    /**
     * @param list<Node\AttributeGroup> $groups
     * @return list<Node\Attribute>
     */
    private function routeAttributes(array $groups): array
    {
        $routes = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->values->attributeName($attribute) === 'Infocyph\\Webrick\\Router\\Definition\\Attribute\\Route') {
                    $routes[] = $attribute;
                }
            }
        }

        return $routes;
    }

    /** @return list<RouteEntry> */
    private function scanClass(Node\Stmt\Class_ $class, string $source, bool $conditional): array
    {
        $className = $class->namespacedName?->toString() ?? $class->name?->toString();
        if ($className === null) {
            return [];
        }

        [$prefix, $domain, $classMiddleware, $namePrefix, $classDynamic] = $this->classContext($class);
        $routes = [];

        foreach ($class->getMethods() as $method) {
            if (!$method->isPublic()) {
                continue;
            }

            [$methodMiddleware, $methodMiddlewareDynamic] = $this->middlewareAttributes($method->attrGroups);
            $methodDynamic = $methodMiddlewareDynamic ? [...$classDynamic, 'middleware'] : $classDynamic;
            foreach ($this->routeAttributes($method->attrGroups) as $attribute) {
                array_push($routes, ...$this->attributeRoutes(
                    $attribute,
                    $className,
                    $method->name->toString(),
                    $prefix,
                    $namePrefix,
                    $domain,
                    [...$classMiddleware, ...$methodMiddleware],
                    $methodDynamic,
                    $conditional,
                    $source,
                ));
            }
        }

        return $routes;
    }

    /**
     * @param list<Node\Stmt> $nodes
     * @param list<RouteEntry> $routes
     */
    private function scanNodes(array $nodes, string $source, bool $conditional, array &$routes): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                $this->scanNodes($node->stmts, $source, $conditional, $routes);

                continue;
            }
            if (!$node instanceof Node\Stmt\Class_ || $node->isAbstract()) {
                continue;
            }

            array_push($routes, ...$this->scanClass($node, $source, $conditional));
        }
    }
}
