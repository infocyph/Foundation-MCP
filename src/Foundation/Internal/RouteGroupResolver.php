<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation\Internal;

use PhpParser\Node;

/**
 * @phpstan-type Scope array{prefix:?string,name_prefix:?string,middleware:list<string>,domain:?string,dynamic:list<string>}
 * @phpstan-type Group array{prefix:?string,name_prefix:?string,middleware:list<string>,domain:?string,callback:?Node\Expr\Closure,dynamic:list<string>}
 */
final readonly class RouteGroupResolver
{
    private const int MAX_CONTEXT_ITEMS = 256;

    private const int MAX_STRING_BYTES = 8_192;

    public function __construct(private RouteValueResolver $values) {}

    /** @param Scope $parent @param Group $group @return Scope */
    public function child(array $parent, array $group): array
    {
        $dynamic = [...$parent['dynamic'], ...$group['dynamic']];
        $namePrefix = $this->combineStrings($parent['name_prefix'], $group['name_prefix']);
        if ($parent['name_prefix'] !== null && $group['name_prefix'] !== null && $namePrefix === null) {
            $dynamic[] = 'name_prefix';
        }

        $middleware = array_values(array_unique([...$parent['middleware'], ...$group['middleware']]));
        if (count($middleware) > self::MAX_CONTEXT_ITEMS) {
            $middleware = [];
            $dynamic[] = 'middleware';
        }

        $dynamic = array_values(array_unique($dynamic));
        if (count($dynamic) > self::MAX_CONTEXT_ITEMS) {
            $dynamic = ['group_context'];
        }

        return [
            'prefix' => $group['prefix'] === null || $parent['prefix'] === null
                ? null
                : $this->values->joinPath($parent['prefix'], $group['prefix']),
            'name_prefix' => $namePrefix,
            'middleware' => $middleware,
            'domain' => $group['domain'] ?? $parent['domain'],
            'dynamic' => $dynamic,
        ];
    }

    /** @return Scope */
    public function emptyScope(): array
    {
        return ['prefix' => '', 'name_prefix' => '', 'middleware' => [], 'domain' => null, 'dynamic' => []];
    }

    /** @param array<int|string,Node\Arg> $args @return Group */
    public function preset(array $args): array
    {
        $presetExpr = $this->values->arg($args, 1, 'preset');
        $callback = $this->closureArg($args, 2, 'callback');
        [$prefix, $prefixDynamic] = $this->groupString($this->values->arg($args, 3, 'prefix'), '');
        [$domain, $domainDynamic] = $this->groupDomain($this->values->arg($args, 4, 'domain'));
        [$name, $nameDynamic] = $this->groupString($this->values->arg($args, 5, 'namePrefix'), '');
        $preset = $presetExpr instanceof Node\Expr ? $this->values->stringValue($presetExpr) : null;
        $dynamic = ['middleware'];
        $preset === null && $dynamic[] = 'preset';
        $prefixDynamic && $dynamic[] = 'prefix';
        $domainDynamic && $dynamic[] = 'domain';
        $nameDynamic && $dynamic[] = 'name_prefix';

        return [
            'prefix' => $prefix,
            'name_prefix' => $name,
            'middleware' => $preset === null ? [] : ['@preset:' . $preset],
            'domain' => $domain,
            'callback' => $callback,
            'dynamic' => $dynamic,
        ];
    }

    /** @param array<int|string,Node\Arg> $args @return Group */
    public function router(array $args): array
    {
        $prefixExpr = $this->values->arg($args, 0, 'prefix');
        $domainExpr = $this->values->arg($args, 1, 'domain');
        $middlewareExpr = $this->values->arg($args, 2, 'middleware');
        $nameExpr = $this->values->arg($args, 3, 'namePrefix');
        $callback = $this->closureArg($args, 4, 'callback');

        if ($prefixExpr instanceof Node\Expr\Array_) {
            return $this->arrayGroup($prefixExpr, $callback ?? $this->closureArg($args, 1, 'callback'));
        }

        $callback ??= $this->implicitCallback($domainExpr, $middlewareExpr, $nameExpr);
        if ($callback === $domainExpr) {
            $domainExpr = null;
        } elseif ($callback === $middlewareExpr) {
            $middlewareExpr = null;
        } elseif ($callback === $nameExpr) {
            $nameExpr = null;
        }

        [$prefix, $prefixDynamic] = $this->groupString($prefixExpr, '');
        [$domain, $domainDynamic] = $this->groupDomain($domainExpr);
        [$name, $nameDynamic] = $this->groupString($nameExpr, '');
        [$middleware, $middlewareDynamic] = $this->groupMiddleware($middlewareExpr);
        $dynamic = [];
        $prefixDynamic && $dynamic[] = 'prefix';
        $domainDynamic && $dynamic[] = 'domain';
        $nameDynamic && $dynamic[] = 'name_prefix';
        $middlewareDynamic && $dynamic[] = 'middleware';

        return [
            'prefix' => $prefix,
            'name_prefix' => $name,
            'middleware' => $middleware,
            'domain' => $domain,
            'callback' => $callback,
            'dynamic' => $dynamic,
        ];
    }

    /** @return Group */
    private function arrayGroup(Node\Expr\Array_ $expr, ?Node\Expr\Closure $callback): array
    {
        $options = $this->values->literalArrayValue($expr);
        if (!is_array($options)) {
            return [
                'prefix' => null,
                'name_prefix' => null,
                'middleware' => [],
                'domain' => null,
                'callback' => $callback,
                'dynamic' => ['prefix', 'name_prefix', 'middleware', 'domain'],
            ];
        }

        return [
            'prefix' => is_string($options['prefix'] ?? null) ? $options['prefix'] : '',
            'name_prefix' => is_string($options['name'] ?? null)
                ? $options['name']
                : (is_string($options['as'] ?? null) ? $options['as'] : ''),
            'middleware' => $this->values->stringList($options['middleware'] ?? null) ?? [],
            'domain' => is_string($options['domain'] ?? null) ? $options['domain'] : null,
            'callback' => $callback,
            'dynamic' => [],
        ];
    }

    /** @param array<int|string,Node\Arg> $args */
    private function closureArg(array $args, int $position, string $name): ?Node\Expr\Closure
    {
        $value = $this->values->arg($args, $position, $name);

        return $value instanceof Node\Expr\Closure ? $value : null;
    }

    private function combineStrings(?string $left, ?string $right): ?string
    {
        if ($left === null || $right === null) {
            return null;
        }

        $value = $left . $right;

        return strlen($value) <= self::MAX_STRING_BYTES ? $value : null;
    }

    /** @return array{0:?string,1:bool} */
    private function groupDomain(?Node\Expr $expr): array
    {
        if ($expr === null || $this->nullLiteral($expr)) {
            return [null, false];
        }
        $value = $this->values->stringValue($expr);

        return $value === null ? [null, true] : [$value, false];
    }

    /** @return array{0:list<string>,1:bool} */
    private function groupMiddleware(?Node\Expr $expr): array
    {
        if ($expr === null) {
            return [[], false];
        }
        $value = $this->values->middlewareValue($expr);

        return $value === null ? [[], true] : [$value, false];
    }

    /** @return array{0:?string,1:bool} */
    private function groupString(?Node\Expr $expr, string $default): array
    {
        if ($expr === null || $this->nullLiteral($expr)) {
            return [$default, false];
        }
        $value = $this->values->stringValue($expr);

        return $value === null ? [null, true] : [$value, false];
    }

    private function implicitCallback(?Node\Expr $domain, ?Node\Expr $middleware, ?Node\Expr $name): ?Node\Expr\Closure
    {
        foreach ([$domain, $middleware, $name] as $candidate) {
            if ($candidate instanceof Node\Expr\Closure) {
                return $candidate;
            }
        }

        return null;
    }

    private function nullLiteral(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\ConstFetch && strtolower($expr->name->toString()) === 'null';
    }
}
