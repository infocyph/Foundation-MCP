<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Analysis\Internal;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\NodeVisitorAbstract;
use RuntimeException;

/** @internal */
final class PhpLiteralVisitor extends NodeVisitorAbstract
{
    private const int MAX_ARRAYS = 128;
    private const int MAX_DEPTH = 8;
    private const int MAX_ITEMS = 256;
    private const int MAX_STRING_BYTES = 8_192;

    private int $arrayDepth = 0;

    /** @var list<array{line:int,value:array<array-key,mixed>}> */
    private array $arrays = [];

    public function enterNode(Node $node)
    {
        if ($node instanceof Expr\Array_) {
            ++$this->arrayDepth;
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if (!$node instanceof Expr\Array_) {
            return null;
        }

        --$this->arrayDepth;

        if ($this->arrayDepth === 0 && count($this->arrays) < self::MAX_ARRAYS) {
            try {
                $value = $this->literal($node, 0);

                if (is_array($value)) {
                    $this->arrays[] = ['line' => $node->getStartLine(), 'value' => $value];
                }
            } catch (RuntimeException) {
            }
        }

        return null;
    }

    /** @return list<array{line:int,value:array<array-key,mixed>}> */
    public function arrays(): array
    {
        return $this->arrays;
    }

    private function literal(Expr $expression, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new RuntimeException('Literal nesting limit exceeded.');
        }

        return match (true) {
            $expression instanceof Expr\Array_ => $this->literalArray($expression, $depth + 1),
            $expression instanceof Scalar\String_ => $this->string($expression->value),
            $expression instanceof Scalar\Int_ => $expression->value,
            $expression instanceof Scalar\Float_ => $expression->value,
            $expression instanceof Expr\ConstFetch => $this->constant($expression),
            $expression instanceof Expr\UnaryMinus => -$this->number($expression->expr, $depth + 1),
            $expression instanceof Expr\UnaryPlus => $this->number($expression->expr, $depth + 1),
            $expression instanceof Expr\BinaryOp\Concat => $this->concat($expression, $depth + 1),
            $expression instanceof Expr\ClassConstFetch => $this->className($expression),
            default => throw new RuntimeException('Expression is not a bounded literal.'),
        };
    }

    /** @return array<array-key, mixed> */
    private function literalArray(Expr\Array_ $array, int $depth): array
    {
        if (count($array->items) > self::MAX_ITEMS) {
            throw new RuntimeException('Literal array item limit exceeded.');
        }

        $result = [];

        foreach ($array->items as $item) {
            if ($item === null || $item->unpack) {
                throw new RuntimeException('Unsupported literal array syntax.');
            }

            $value = $this->literal($item->value, $depth);

            if ($item->key === null) {
                $result[] = $value;

                continue;
            }

            $key = $this->literal($item->key, $depth);

            if (!is_int($key) && !is_string($key)) {
                throw new RuntimeException('Literal array key must be integer or string.');
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function constant(Expr\ConstFetch $constant): bool|null
    {
        return match (strtolower($constant->name->toString())) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => throw new RuntimeException('Constant is not a literal value.'),
        };
    }

    private function number(Expr $expression, int $depth): int|float
    {
        $value = $this->literal($expression, $depth);

        if (!is_int($value) && !is_float($value)) {
            throw new RuntimeException('Unary literal must be numeric.');
        }

        return $value;
    }

    private function concat(Expr\BinaryOp\Concat $expression, int $depth): string
    {
        $left = $this->literal($expression->left, $depth);
        $right = $this->literal($expression->right, $depth);

        if (!is_string($left) || !is_string($right)) {
            throw new RuntimeException('Literal concatenation must use strings.');
        }

        return $this->string($left.$right);
    }

    private function className(Expr\ClassConstFetch $expression): string
    {
        if (!$expression->class instanceof Name || !$expression->name instanceof Node\Identifier) {
            throw new RuntimeException('Dynamic class constant is not a literal value.');
        }

        if (strtolower($expression->name->toString()) !== 'class') {
            throw new RuntimeException('Only ::class is a literal class constant.');
        }

        $resolved = $expression->class->getAttribute('resolvedName');
        $name = $resolved instanceof Name ? $resolved : $expression->class;

        return $this->string($name->toString());
    }

    private function string(string $value): string
    {
        if (strlen($value) > self::MAX_STRING_BYTES) {
            throw new RuntimeException('Literal string size limit exceeded.');
        }

        return $value;
    }
}
