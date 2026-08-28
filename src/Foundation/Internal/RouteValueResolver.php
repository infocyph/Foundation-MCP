<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation\Internal;

use PhpParser\Node;
use RuntimeException;

final readonly class RouteValueResolver
{
    /** @param array<int|string, Node\Arg> $args */
    public function arg(array $args, int $position, string $name): ?Node\Expr
    {
        $arg = $args[$name] ?? $args[$position] ?? null;

        return $arg instanceof Node\Arg ? $arg->value : null;
    }

    /** @return array<int|string, Node\Arg> */
    public function attributeArgs(Node\Attribute $attribute): array
    {
        return $this->indexArgs($attribute->args);
    }

    public function attributeName(Node\Attribute $attribute): string
    {
        return $this->resolvedName($attribute->name);
    }

    /** @return array<int|string, Node\Arg> */
    public function callArgs(Node\Expr $expr): array
    {
        if (!$expr instanceof Node\Expr\MethodCall && !$expr instanceof Node\Expr\StaticCall) {
            return [];
        }

        return $this->indexArgs($expr->args);
    }

    public function classString(Node\Expr $expr): ?string
    {
        if (
            $expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && strtolower($expr->name->toString()) === 'class'
        ) {
            return $this->resolvedName($expr->class);
        }

        return $this->stringValue($expr);
    }

    public function handler(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Expr\Array_) {
            $items = $expr->items;
            $class = isset($items[0]) && $items[0] instanceof Node\Expr\ArrayItem
                ? $this->classString($items[0]->value)
                : null;
            $method = isset($items[1]) && $items[1] instanceof Node\Expr\ArrayItem
                ? $this->stringValue($items[1]->value)
                : null;

            return $class !== null && $method !== null ? $class . '::' . $method : null;
        }

        if (
            $expr instanceof Node\Expr\StaticCall
            && $expr->isFirstClassCallable()
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
        ) {
            return $this->resolvedName($expr->class) . '::' . $expr->name->toString();
        }

        if ($expr instanceof Node\Expr\FuncCall && $expr->isFirstClassCallable() && $expr->name instanceof Node\Name) {
            return $this->resolvedName($expr->name);
        }

        if ($expr instanceof Node\Expr\Closure || $expr instanceof Node\Expr\ArrowFunction) {
            return 'closure';
        }

        return $this->stringValue($expr);
    }

    public function joinPath(?string $prefix, string $path): ?string
    {
        if ($prefix === null) {
            return null;
        }

        return '/' . ltrim(trim($prefix, '/') . '/' . ltrim($path, '/'), '/');
    }

    /** @return array<array-key,mixed>|null */
    public function literalArrayValue(Node\Expr $expr): ?array
    {
        try {
            $value = $this->literalValue($expr);
        } catch (RuntimeException) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    /** @return list<string> */
    public function methodList(Node\Expr $expr): array
    {
        $single = $this->stringValue($expr);
        if ($single !== null) {
            return [strtoupper($single)];
        }

        $array = $this->literalArrayValue($expr);
        $list = $this->stringList($array);

        return $list === null ? [] : array_values(array_map(strtoupper(...), $list));
    }

    /** @return list<string>|null */
    public function middlewareValue(Node\Expr $expr): ?array
    {
        $value = $this->literalArrayValue($expr);

        return $this->stringList($value);
    }

    public function nullableString(mixed $value): ?string
    {
        return $value === null || is_string($value) ? $value : null;
    }

    public function nullableStringExpr(Node\Expr $expr): ?string
    {
        try {
            $value = $this->literalValue($expr);
        } catch (RuntimeException) {
            return null;
        }

        return $value === null || is_string($value) ? $value : null;
    }

    public function resolvedName(Node\Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return $resolved instanceof Node\Name ? $resolved->toString() : $name->toString();
    }

    /**
     * @return array{0:?string,1:list<string>,2:list<string>,3:array<string,mixed>,4:bool}
     */
    public function routeOptions(?Node\Expr $expr): array
    {
        if ($expr === null) {
            return [null, [], [], [], false];
        }

        $string = $this->stringValue($expr);
        if ($string !== null) {
            return [$string, [], [], [], false];
        }

        $value = $this->literalArrayValue($expr);
        if (!is_array($value)) {
            return [null, [], [], [], true];
        }

        $name = is_string($value['name'] ?? null)
            ? $value['name']
            : (is_string($value['as'] ?? null) ? $value['as'] : null);
        $middleware = $this->stringList($value['middleware'] ?? null) ?? [];
        $aliasValue = $value['aliases'] ?? $value['alias'] ?? [];
        $aliases = is_string($aliasValue) ? [$aliasValue] : ($this->stringList($aliasValue) ?? []);

        return [$name, $middleware, $aliases, $value, false];
    }

    /** @return list<string>|null */
    public function stringList(mixed $value): ?array
    {
        if ($value === null || !is_array($value)) {
            return null;
        }

        $list = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                return null;
            }
            $list[] = $item;
        }

        return $list;
    }

    /** @return array<string,string> */
    public function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item) && $item !== '') {
                $map[$key] = $item;
            }
        }

        return $map;
    }

    public function stringValue(Node\Expr $expr): ?string
    {
        try {
            $value = $this->literalValue($expr);
        } catch (RuntimeException) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    /** @param list<string> $values @return list<string> */
    public function uniqueStrings(array $values): array
    {
        $values = array_values(array_unique(array_filter(
            $values,
            static fn(mixed $value): bool => is_string($value) && $value !== '',
        )));
        sort($values, SORT_STRING);

        return $values;
    }

    private function classConstantValue(Node\Expr\ClassConstFetch $expr): string
    {
        if (
            !$expr->class instanceof Node\Name
            || !$expr->name instanceof Node\Identifier
            || strtolower($expr->name->toString()) !== 'class'
        ) {
            throw new RuntimeException('Unsupported route class constant.');
        }

        return $this->resolvedName($expr->class);
    }

    private function constantValue(Node\Expr\ConstFetch $expr): ?bool
    {
        return match (strtolower($expr->name->toString())) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => throw new RuntimeException('Unsupported route constant.'),
        };
    }

    /** @param list<Node\Arg> $raw @return array<int|string,Node\Arg> */
    private function indexArgs(array $raw): array
    {
        $args = [];
        foreach ($raw as $position => $arg) {
            $args[$position] = $arg;
            if ($arg->name instanceof Node\Identifier) {
                $args[$arg->name->toString()] = $arg;
            }
        }

        return $args;
    }

    /** @return array<array-key,mixed> */
    private function literalArray(Node\Expr\Array_ $array): array
    {
        $result = [];
        foreach ($array->items as $item) {
            if (!$item instanceof Node\Expr\ArrayItem || $item->unpack) {
                throw new RuntimeException('Unsupported route array expression.');
            }

            $value = $this->literalValue($item->value);
            if ($item->key === null) {
                $result[] = $value;

                continue;
            }

            $key = $this->literalValue($item->key);
            if (!is_string($key) && !is_int($key)) {
                throw new RuntimeException('Unsupported route array key.');
            }
            $result[$key] = $value;
        }

        return $result;
    }

    private function literalValue(Node\Expr $expr): mixed
    {
        return match (true) {
            $expr instanceof Node\Scalar\String_ => $expr->value,
            $expr instanceof Node\Scalar\Int_ => $expr->value,
            $expr instanceof Node\Scalar\Float_ => $expr->value,
            $expr instanceof Node\Expr\Array_ => $this->literalArray($expr),
            $expr instanceof Node\Expr\ConstFetch => $this->constantValue($expr),
            $expr instanceof Node\Expr\ClassConstFetch => $this->classConstantValue($expr),
            $expr instanceof Node\Expr\BinaryOp\Concat => $this->literalValue($expr->left) . $this->literalValue($expr->right),
            default => throw new RuntimeException('Non-literal route expression.'),
        };
    }
}
