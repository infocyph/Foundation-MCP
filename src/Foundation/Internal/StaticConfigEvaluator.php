<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation\Internal;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use RuntimeException;

/**
 * @phpstan-type EnvironmentRef array{name:string,helper:string,has_default:bool,default:mixed}
 * @phpstan-type EvalResult array{status:'literal'|'environment'|'dynamic',value:mixed,environment:list<EnvironmentRef>,classes:list<string>}
 */
final class StaticConfigEvaluator
{
    private const int MAX_DEPTH = 64;

    private const int MAX_STRING_BYTES = 8_192;

    private const int MAX_VARIABLES = 512;

    private int $depth = 0;

    /** @var array<string, EvalResult> */
    private array $variables = [];

    /** @return EvalResult */
    public function evaluate(Expr $expr): array
    {
        if (++$this->depth > self::MAX_DEPTH) {
            --$this->depth;
            throw new RuntimeException('Static config expression exceeds the 64-level evaluation depth limit.');
        }

        try {
            return match (true) {
                $expr instanceof Scalar\String_ => $this->literal($this->string($expr->value)),
                $expr instanceof Scalar\Int_ => $this->literal($expr->value),
                $expr instanceof Scalar\Float_ => $this->literal($expr->value),
                $expr instanceof Expr\ConstFetch => $this->constant($expr),
                $expr instanceof Expr\ClassConstFetch => $this->classConstant($expr),
                $expr instanceof Expr\Variable => $this->variable($expr),
                $expr instanceof Expr\FuncCall => $this->functionCall($expr),
                $expr instanceof Expr\UnaryMinus => $this->numericUnary($expr->expr, -1),
                $expr instanceof Expr\UnaryPlus => $this->numericUnary($expr->expr, 1),
                $expr instanceof Expr\BooleanNot => $this->booleanNot($expr),
                $expr instanceof Expr\BinaryOp\Concat => $this->concat($expr),
                $expr instanceof Expr\BinaryOp => $this->binary($expr),
                $expr instanceof Expr\Ternary => $this->ternary($expr),
                $expr instanceof Expr\PropertyFetch, $expr instanceof Expr\StaticPropertyFetch => $this->dynamicWithClasses($expr),
                default => $this->dynamic(),
            };
        } finally {
            --$this->depth;
        }
    }

    /** @param list<Node\Stmt> $statements */
    public function learn(array $statements): void
    {
        foreach ($statements as $statement) {
            if (!$statement instanceof Node\Stmt\Expression || !$statement->expr instanceof Expr\Assign) {
                continue;
            }
            $assign = $statement->expr;
            if ($assign->var instanceof Expr\Variable && is_string($assign->var->name)) {
                if (!isset($this->variables[$assign->var->name]) && count($this->variables) >= self::MAX_VARIABLES) {
                    throw new RuntimeException('Static config evaluation exceeds the 512-variable limit.');
                }

                $this->variables[$assign->var->name] = $this->evaluate($assign->expr);
            }
        }
    }

    /** @return EvalResult */
    private function binary(Expr\BinaryOp $expr): array
    {
        $left = $this->evaluate($expr->left);
        $right = $this->evaluate($expr->right);
        if ($left['status'] !== 'literal' || $right['status'] !== 'literal') {
            return $this->combineDynamic($left, $right);
        }

        $value = match (true) {
            $expr instanceof Expr\BinaryOp\Identical => $left['value'] === $right['value'],
            $expr instanceof Expr\BinaryOp\NotIdentical => $left['value'] !== $right['value'],
            $expr instanceof Expr\BinaryOp\Equal => $left['value'] == $right['value'],
            $expr instanceof Expr\BinaryOp\NotEqual => $left['value'] != $right['value'],
            $expr instanceof Expr\BinaryOp\BooleanAnd => (bool) $left['value'] && (bool) $right['value'],
            $expr instanceof Expr\BinaryOp\BooleanOr => (bool) $left['value'] || (bool) $right['value'],
            default => null,
        };

        return $value === null ? $this->combineDynamic($left, $right) : $this->literal($value);
    }

    /** @return EvalResult */
    private function booleanNot(Expr\BooleanNot $expr): array
    {
        $value = $this->evaluate($expr->expr);
        if ($value['status'] === 'literal') {
            return $this->literal(!$value['value']);
        }

        return $this->merge('dynamic', null, $value['environment'], $value['classes']);
    }

    /** @return EvalResult */
    private function classConstant(Expr\ClassConstFetch $expr): array
    {
        if (!$expr->class instanceof Name || !$expr->name instanceof Node\Identifier) {
            return $this->dynamic();
        }
        $class = $this->resolvedName($expr->class);
        if (strtolower($expr->name->toString()) === 'class') {
            return ['status' => 'literal', 'value' => $class, 'environment' => [], 'classes' => [$class]];
        }

        return ['status' => 'dynamic', 'value' => null, 'environment' => [], 'classes' => [$class]];
    }

    /** @param EvalResult $left @param EvalResult $right @return EvalResult */
    private function combineDynamic(array $left, array $right): array
    {
        return $this->merge(
            'dynamic',
            null,
            [...$left['environment'], ...$right['environment']],
            [...$left['classes'], ...$right['classes']],
        );
    }

    /** @return EvalResult */
    private function concat(Expr\BinaryOp\Concat $expr): array
    {
        $left = $this->evaluate($expr->left);
        $right = $this->evaluate($expr->right);
        if ($left['status'] === 'literal' && $right['status'] === 'literal'
            && is_string($left['value']) && is_string($right['value'])) {
            return $this->literal($this->string($left['value'] . $right['value']));
        }

        return $this->combineDynamic($left, $right);
    }

    /** @return EvalResult */
    private function constant(Expr\ConstFetch $expr): array
    {
        return match (strtolower($expr->name->toString())) {
            'true' => $this->literal(true),
            'false' => $this->literal(false),
            'null' => $this->literal(null),
            default => $this->dynamic(),
        };
    }

    /** @return EvalResult */
    private function dynamic(): array
    {
        return ['status' => 'dynamic', 'value' => null, 'environment' => [], 'classes' => []];
    }

    /** @param list<Node\Arg> $args @return EvalResult */
    private function dynamicFromArgs(array $args): array
    {
        $environment = [];
        $classes = [];
        foreach ($args as $arg) {
            $value = $this->evaluate($arg->value);
            array_push($environment, ...$value['environment']);
            array_push($classes, ...$value['classes']);
        }

        return $this->merge('dynamic', null, $environment, $classes);
    }

    /** @return EvalResult */
    private function dynamicWithClasses(Expr $expr): array
    {
        $classes = [];
        foreach ($expr->getSubNodeNames() as $name) {
            $value = $expr->{$name};
            if ($value instanceof Name) {
                $classes[] = $this->resolvedName($value);
            } elseif ($value instanceof Expr\ClassConstFetch && $value->class instanceof Name) {
                $classes[] = $this->resolvedName($value->class);
            }
        }

        return $this->merge('dynamic', null, [], $classes);
    }

    /** @return EvalResult */
    private function functionCall(Expr\FuncCall $expr): array
    {
        if (!$expr->name instanceof Name) {
            return $this->dynamicFromArgs($expr->args);
        }
        $helper = strtolower($expr->name->toString());
        if (!in_array($helper, ['env', 'env_string', 'env_bool', 'env_int', 'env_float', 'env_array'], true)) {
            return $this->dynamicFromArgs($expr->args);
        }

        $name = isset($expr->args[0]) ? $this->evaluate($expr->args[0]->value) : $this->dynamic();
        $default = isset($expr->args[1]) ? $this->evaluate($expr->args[1]->value) : $this->literal(null);
        if ($name['status'] !== 'literal' || !is_string($name['value']) || $name['value'] === '') {
            return $this->merge('dynamic', null, $default['environment'], $default['classes']);
        }

        $environment = $default['environment'];
        $environment[] = [
            'name' => $name['value'],
            'helper' => $helper,
            'has_default' => isset($expr->args[1]),
            'default' => $default['status'] === 'literal' ? $default['value'] : null,
        ];

        return $this->merge('environment', null, $environment, $default['classes']);
    }

    /** @return EvalResult */
    private function literal(mixed $value): array
    {
        return ['status' => 'literal', 'value' => $value, 'environment' => [], 'classes' => []];
    }

    /** @param list<EnvironmentRef> $environment @param list<string> $classes @return EvalResult */
    private function merge(string $status, mixed $value, array $environment, array $classes): array
    {
        $env = [];
        foreach ($environment as $item) {
            $env[strtolower($item['name']) . '|' . $item['helper']] = $item;
        }
        $classMap = [];
        foreach ($classes as $class) {
            if ($class !== '') {
                $classMap[$class] = true;
            }
        }

        return ['status' => $status, 'value' => $value, 'environment' => array_values($env), 'classes' => array_keys($classMap)];
    }

    /** @return EvalResult */
    private function numericUnary(Expr $expr, int $sign): array
    {
        $value = $this->evaluate($expr);
        if ($value['status'] === 'literal' && (is_int($value['value']) || is_float($value['value']))) {
            return $this->literal($sign * $value['value']);
        }

        return $this->merge('dynamic', null, $value['environment'], $value['classes']);
    }

    private function resolvedName(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return ltrim(($resolved instanceof Name ? $resolved : $name)->toString(), '\\');
    }

    private function string(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new RuntimeException('Static config string literal must be valid UTF-8.');
        }

        if (strlen($value) <= self::MAX_STRING_BYTES) {
            return $value;
        }

        $value = substr($value, 0, self::MAX_STRING_BYTES);

        while ($value !== '' && preg_match('//u', $value) !== 1) {
            $value = substr($value, 0, -1);
        }

        return $value;
    }

    /** @return EvalResult */
    private function ternary(Expr\Ternary $expr): array
    {
        $condition = $this->evaluate($expr->cond);
        if ($condition['status'] === 'literal') {
            return $this->evaluate((bool) $condition['value'] ? ($expr->if ?? $expr->cond) : $expr->else);
        }
        $left = $this->evaluate($expr->if ?? $expr->cond);
        $right = $this->evaluate($expr->else);

        return $this->merge(
            'dynamic',
            null,
            [...$condition['environment'], ...$left['environment'], ...$right['environment']],
            [...$condition['classes'], ...$left['classes'], ...$right['classes']],
        );
    }

    /** @return EvalResult */
    private function variable(Expr\Variable $expr): array
    {
        return is_string($expr->name) ? ($this->variables[$expr->name] ?? $this->dynamic()) : $this->dynamic();
    }
}
