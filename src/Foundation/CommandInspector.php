<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation\Internal;

use PhpParser\Node;

/**
 * Static reader for CommandHandlerInterface::define(CommandDefinition $command).
 *
 * @phpstan-type CommandMetadata array{
 *   name:?string,description:?string,group:?string,runtime:?string,capabilities:list<string>,aliases:list<string>,
 *   hidden:?bool,arguments:list<array{name:?string,description:?string,required:?bool,variadic:?bool}>,
 *   options:list<array{name:?string,description:?string,short:?string,accepts_value:?bool,multiple:?bool,negatable:?bool}>,
 *   dynamic_fields:list<string>
 * }
 */
final readonly class CommandDefinitionScanner
{
    /** @return CommandMetadata */
    public function scan(Node\Stmt\Class_ $class): array
    {
        $metadata = $this->defaults();
        $method = $class->getMethod('define');
        if (!$method instanceof Node\Stmt\ClassMethod) {
            $metadata['dynamic_fields'][] = 'definition';
            return $metadata;
        }

        $parameter = $method->params[0] ?? null;
        if (!$parameter instanceof Node\Param || !$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name)) {
            $metadata['dynamic_fields'][] = 'definition';
            return $metadata;
        }

        $variable = $parameter->var->name;
        foreach ($method->stmts ?? [] as $statement) {
            $this->scanNode($statement, $variable, $metadata, false);
        }

        $metadata['capabilities'] = $this->unique($metadata['capabilities']);
        $metadata['aliases'] = $this->unique($metadata['aliases']);
        $metadata['dynamic_fields'] = $this->unique($metadata['dynamic_fields']);

        return $metadata;
    }

    /** @param CommandMetadata $metadata */
    private function scanNode(Node $node, string $variable, array &$metadata, bool $conditional): void
    {
        if ($node instanceof Node\Stmt\Expression) {
            $this->scanExpression($node->expr, $variable, $metadata, $conditional);
            return;
        }

        if ($node instanceof Node\Stmt\If_) {
            $this->markConditional($node->stmts, $variable, $metadata);
            foreach ($node->elseifs as $elseif) {
                $this->markConditional($elseif->stmts, $variable, $metadata);
            }
            if ($node->else !== null) {
                $this->markConditional($node->else->stmts, $variable, $metadata);
            }
            return;
        }

        if ($node instanceof Node\Stmt\Foreach_ || $node instanceof Node\Stmt\For_ || $node instanceof Node\Stmt\While_ || $node instanceof Node\Stmt\Do_) {
            $this->markConditional($node->stmts, $variable, $metadata);
            return;
        }

        if ($node instanceof Node\Stmt\TryCatch) {
            $this->markConditional($node->stmts, $variable, $metadata);
            foreach ($node->catches as $catch) {
                $this->markConditional($catch->stmts, $variable, $metadata);
            }
            if ($node->finally !== null) {
                $this->markConditional($node->finally->stmts, $variable, $metadata);
            }
        }
    }

    /** @param list<Node\Stmt> $nodes @param CommandMetadata $metadata */
    private function markConditional(array $nodes, string $variable, array &$metadata): void
    {
        foreach ($nodes as $node) {
            $this->scanNode($node, $variable, $metadata, true);
        }
    }

    /** @param CommandMetadata $metadata */
    private function scanExpression(Node\Expr $expr, string $variable, array &$metadata, bool $conditional): void
    {
        if (!$expr instanceof Node\Expr\MethodCall) {
            return;
        }

        $chain = $this->chain($expr, $variable);
        if ($chain === null) {
            return;
        }

        foreach ($chain as [$method, $args]) {
            $this->apply($method, $args, $metadata, $conditional);
        }
    }

    /**
     * @return list<array{0:string,1:list<Node\Arg>}>|null
     */
    private function chain(Node\Expr\MethodCall $call, string $variable): ?array
    {
        $calls = [];
        $cursor = $call;

        while ($cursor instanceof Node\Expr\MethodCall) {
            if (!$cursor->name instanceof Node\Identifier) {
                return null;
            }
            array_unshift($calls, [$cursor->name->toString(), $cursor->args]);
            $cursor = $cursor->var;
        }

        return $cursor instanceof Node\Expr\Variable && $cursor->name === $variable ? $calls : null;
    }

    /** @param list<Node\Arg> $args @param CommandMetadata $metadata */
    private function apply(string $method, array $args, array &$metadata, bool $conditional): void
    {
        $method = strtolower($method);
        if ($conditional) {
            $metadata['dynamic_fields'][] = $this->fieldFor($method);
        }

        match ($method) {
            'name' => $this->scalarField($metadata, 'name', $this->stringArg($args, 0, 'name')),
            'description' => $this->scalarField($metadata, 'description', $this->stringArg($args, 0, 'description')),
            'group' => $this->scalarField($metadata, 'group', $this->stringArg($args, 0, 'group')),
            'runtime' => $this->scalarField($metadata, 'runtime', $this->runtimeArg($args, 0, 'runtime')),
            'capability' => $this->listField($metadata, 'capabilities', $this->stringArg($args, 0, 'capability')),
            'alias' => $this->listField($metadata, 'aliases', $this->stringArg($args, 0, 'alias')),
            'hidden' => $this->scalarField($metadata, 'hidden', $this->boolArg($args, 0, 'hidden', true)),
            'argument' => $this->argument($metadata, $args),
            'option' => $this->option($metadata, $args),
            'execution' => $metadata['dynamic_fields'][] = 'execution',
            default => null,
        };
    }

    /** @param CommandMetadata $metadata */
    private function scalarField(array &$metadata, string $field, string|bool|null $value): void
    {
        if ($value === null) {
            $metadata['dynamic_fields'][] = $field;
            return;
        }
        $metadata[$field] = $value;
    }

    /** @param CommandMetadata $metadata */
    private function listField(array &$metadata, string $field, ?string $value): void
    {
        if ($value === null) {
            $metadata['dynamic_fields'][] = $field;
            return;
        }
        $metadata[$field][] = $value;
    }

    /** @param CommandMetadata $metadata @param list<Node\Arg> $args */
    private function argument(array &$metadata, array $args): void
    {
        $name = $this->stringArg($args, 0, 'name');
        $description = $this->stringArg($args, 1, 'description', '');
        $required = $this->boolArg($args, 2, 'required', false);
        $variadic = $this->boolArg($args, 3, 'variadic', false);
        if ($name === null || $description === null || $required === null || $variadic === null) {
            $metadata['dynamic_fields'][] = 'arguments';
        }
        $metadata['arguments'][] = compact('name', 'description', 'required', 'variadic');
    }

    /** @param CommandMetadata $metadata @param list<Node\Arg> $args */
    private function option(array &$metadata, array $args): void
    {
        $name = $this->stringArg($args, 0, 'name');
        $description = $this->stringArg($args, 1, 'description', '');
        $short = $this->nullableStringArg($args, 2, 'short');
        $acceptsValue = $this->boolArg($args, 3, 'acceptsValue', false);
        $multiple = $this->boolArg($args, 4, 'multiple', false);
        $negatable = $this->boolArg($args, 5, 'negatable', false);
        if ($name === null || $description === null || $short === false || $acceptsValue === null || $multiple === null || $negatable === null) {
            $metadata['dynamic_fields'][] = 'options';
        }
        $short = $short === false ? null : $short;
        $metadata['options'][] = [
            'name' => $name,
            'description' => $description,
            'short' => $short,
            'accepts_value' => $acceptsValue,
            'multiple' => $multiple,
            'negatable' => $negatable,
        ];
    }

    /** @param list<Node\Arg> $args */
    private function stringArg(array $args, int $position, string $name, ?string $default = null): ?string
    {
        $expr = $this->arg($args, $position, $name);
        if ($expr === null) {
            return $default;
        }
        return $expr instanceof Node\Scalar\String_ ? $expr->value : null;
    }

    /** @param list<Node\Arg> $args @return string|false|null */
    private function nullableStringArg(array $args, int $position, string $name): string|false|null
    {
        $expr = $this->arg($args, $position, $name);
        if ($expr === null || $this->nullLiteral($expr)) {
            return null;
        }
        return $expr instanceof Node\Scalar\String_ ? $expr->value : false;
    }

    /** @param list<Node\Arg> $args */
    private function boolArg(array $args, int $position, string $name, ?bool $default = null): ?bool
    {
        $expr = $this->arg($args, $position, $name);
        if ($expr === null) {
            return $default;
        }
        if (!$expr instanceof Node\Expr\ConstFetch) {
            return null;
        }
        return match (strtolower($expr->name->toString())) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }

    /** @param list<Node\Arg> $args */
    private function runtimeArg(array $args, int $position, string $name): ?string
    {
        $expr = $this->arg($args, $position, $name);
        if (!$expr instanceof Node\Expr\ClassConstFetch || !$expr->class instanceof Node\Name || !$expr->name instanceof Node\Identifier) {
            return null;
        }
        $class = $expr->class->getAttribute('resolvedName');
        $className = $class instanceof Node\Name ? $class->toString() : $expr->class->toString();
        if ($className !== 'Infocyph\\Foundation\\Application\\RuntimeMode' && $className !== 'RuntimeMode') {
            return null;
        }

        return match ($expr->name->toString()) {
            'Cli' => 'cli',
            'Scheduler' => 'scheduler',
            'Web' => 'web',
            'Worker' => 'worker',
            default => null,
        };
    }

    /** @param list<Node\Arg> $args */
    private function arg(array $args, int $position, string $name): ?Node\Expr
    {
        foreach ($args as $index => $arg) {
            if ($arg->name instanceof Node\Identifier && $arg->name->toString() === $name) {
                return $arg->value;
            }
            if ($index === $position && $arg->name === null) {
                return $arg->value;
            }
        }
        return null;
    }

    private function nullLiteral(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\ConstFetch && strtolower($expr->name->toString()) === 'null';
    }

    private function fieldFor(string $method): string
    {
        return match ($method) {
            'capability' => 'capabilities',
            'alias' => 'aliases',
            'argument' => 'arguments',
            'option' => 'options',
            default => $method,
        };
    }

    /** @param list<string> $values @return list<string> */
    private function unique(array $values): array
    {
        $values = array_values(array_unique(array_filter($values, static fn (string $value): bool => $value !== '')));
        sort($values, SORT_STRING);
        return $values;
    }

    /** @return CommandMetadata */
    private function defaults(): array
    {
        return [
            'name' => null,
            'description' => '',
            'group' => 'Application',
            'runtime' => 'cli',
            'capabilities' => [],
            'aliases' => [],
            'hidden' => false,
            'arguments' => [],
            'options' => [],
            'dynamic_fields' => [],
        ];
    }
}
