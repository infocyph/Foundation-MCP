<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Analysis\Internal;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/** @internal */
final class PhpDeclarationVisitor extends NodeVisitorAbstract
{
    private ?string $namespace = null;

    /** @var list<string> */
    private array $namespaces = [];

    /** @var list<array{namespace:?string,kind:string,alias:string,target:string,line:int}> */
    private array $imports = [];

    /** @var list<array<string, mixed>> */
    private array $declarations = [];

    /** @var list<?string> */
    private array $classStack = [];

    /** @var list<?int> */
    private array $classDeclarationStack = [];

    public function enterNode(Node $node)
    {
        match (true) {
            $node instanceof Stmt\Namespace_ => $this->collectNamespace($node),
            $node instanceof Stmt\Use_ => $this->collectUse($node),
            $node instanceof Stmt\GroupUse => $this->collectGroupUse($node),
            $node instanceof Stmt\ClassLike => $this->collectClassLike($node),
            $node instanceof Stmt\ClassMethod => $this->collectMethod($node),
            $node instanceof Stmt\Function_ => $this->collectFunction($node),
            $node instanceof Stmt\Property => $this->collectProperties($node),
            $node instanceof Stmt\ClassConst => $this->collectClassConstants($node),
            $node instanceof Stmt\Const_ => $this->collectConstants($node),
            $node instanceof Stmt\EnumCase => $this->collectEnumCase($node),
            $node instanceof Stmt\TraitUse => $this->collectTraitUse($node),
            default => null,
        };

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Stmt\ClassLike) {
            array_pop($this->classStack);
            array_pop($this->classDeclarationStack);
        }

        return null;
    }

    /** @return list<string> */
    public function namespaces(): array
    {
        return $this->namespaces;
    }

    /** @return list<array{namespace:?string,kind:string,alias:string,target:string,line:int}> */
    public function imports(): array
    {
        return $this->imports;
    }

    /** @return list<array<string, mixed>> */
    public function declarations(): array
    {
        return $this->declarations;
    }

    private function collectNamespace(Stmt\Namespace_ $node): void
    {
        $this->namespace = $node->name?->toString();

        if ($this->namespace !== null && !in_array($this->namespace, $this->namespaces, true)) {
            $this->namespaces[] = $this->namespace;
        }
    }

    private function collectUse(Stmt\Use_ $use): void
    {
        foreach ($use->uses as $item) {
            $type = $item->type !== Stmt\Use_::TYPE_UNKNOWN ? $item->type : $use->type;
            $this->imports[] = [
                'namespace' => $this->namespace,
                'kind' => $this->useKind($type),
                'alias' => $item->alias?->toString() ?? $item->name->getLast(),
                'target' => $item->name->toString(),
                'line' => $item->getStartLine(),
            ];
        }
    }

    private function collectGroupUse(Stmt\GroupUse $use): void
    {
        $prefix = $use->prefix->toString();

        foreach ($use->uses as $item) {
            $type = $item->type !== Stmt\Use_::TYPE_UNKNOWN ? $item->type : $use->type;
            $this->imports[] = [
                'namespace' => $this->namespace,
                'kind' => $this->useKind($type),
                'alias' => $item->alias?->toString() ?? $item->name->getLast(),
                'target' => $prefix.'\\'.$item->name->toString(),
                'line' => $item->getStartLine(),
            ];
        }
    }

    private function collectClassLike(Stmt\ClassLike $node): void
    {
        if ($node->name === null) {
            $this->classStack[] = null;
            $this->classDeclarationStack[] = null;

            return;
        }

        $symbol = $this->declarationName($node);
        $extends = $this->extends($node);
        $implements = $this->implements($node);
        $index = count($this->declarations);
        $this->declarations[] = $this->declaration(
            kind: $this->classKind($node),
            name: $node->name->toString(),
            symbol: $symbol,
            node: $node,
            visibility: null,
            static: false,
            abstract: $node instanceof Stmt\Class_ && $node->isAbstract(),
            final: $node instanceof Stmt\Class_ && $node->isFinal(),
            readonly: $node instanceof Stmt\Class_ && $node->isReadonly(),
            type: $node instanceof Stmt\Enum_ ? $this->type($node->scalarType) : null,
            parameters: [],
            extends: $extends,
            implements: $implements,
            traits: [],
            attributes: $this->attributes($node->attrGroups),
        );
        $this->classStack[] = $symbol;
        $this->classDeclarationStack[] = $index;
    }

    private function collectMethod(Stmt\ClassMethod $node): void
    {
        $class = $this->currentClass();

        if ($class === null) {
            return;
        }

        $parameters = $this->parameters($node->params);
        $this->declarations[] = $this->declaration(
            kind: 'method',
            name: $node->name->toString(),
            symbol: $class.'::'.$node->name->toString(),
            node: $node,
            visibility: $this->visibility($node),
            static: $node->isStatic(),
            abstract: $node->isAbstract(),
            final: $node->isFinal(),
            readonly: false,
            type: $this->type($node->returnType),
            parameters: $parameters,
            extends: [],
            implements: [],
            traits: [],
            attributes: $this->attributes($node->attrGroups),
        );

        if (strtolower($node->name->toString()) === '__construct') {
            $this->collectPromotedProperties($class, $node->params);
        }
    }

    private function collectFunction(Stmt\Function_ $node): void
    {
        $this->declarations[] = $this->declaration(
            kind: 'function',
            name: $node->name->toString(),
            symbol: $this->declarationName($node),
            node: $node,
            visibility: null,
            static: false,
            abstract: false,
            final: false,
            readonly: false,
            type: $this->type($node->returnType),
            parameters: $this->parameters($node->params),
            extends: [],
            implements: [],
            traits: [],
            attributes: $this->attributes($node->attrGroups),
        );
    }

    private function collectProperties(Stmt\Property $node): void
    {
        $class = $this->currentClass();

        if ($class === null) {
            return;
        }

        foreach ($node->props as $property) {
            $name = $property->name->toString();
            $this->declarations[] = $this->declaration(
                kind: 'property',
                name: $name,
                symbol: $class.'::$'.$name,
                node: $property,
                visibility: $this->visibility($node),
                static: $node->isStatic(),
                abstract: false,
                final: false,
                readonly: $node->isReadonly(),
                type: $this->type($node->type),
                parameters: [],
                extends: [],
                implements: [],
                traits: [],
                attributes: $this->attributes($node->attrGroups),
                docNode: $node,
            );
        }
    }

    private function collectClassConstants(Stmt\ClassConst $node): void
    {
        $class = $this->currentClass();

        if ($class === null) {
            return;
        }

        foreach ($node->consts as $constant) {
            $name = $constant->name->toString();
            $this->declarations[] = $this->declaration(
                kind: 'class_constant',
                name: $name,
                symbol: $class.'::'.$name,
                node: $constant,
                visibility: $this->visibility($node),
                static: true,
                abstract: false,
                final: $node->isFinal(),
                readonly: false,
                type: $this->type($node->type),
                parameters: [],
                extends: [],
                implements: [],
                traits: [],
                attributes: $this->attributes($node->attrGroups),
                docNode: $node,
            );
        }
    }

    private function collectConstants(Stmt\Const_ $node): void
    {
        foreach ($node->consts as $constant) {
            $name = $constant->name->toString();
            $namespaced = $constant->namespacedName ?? null;
            $symbol = $namespaced instanceof Name
                ? $namespaced->toString()
                : ($this->namespace !== null ? $this->namespace.'\\'.$name : $name);
            $this->declarations[] = $this->declaration(
                kind: 'constant',
                name: $name,
                symbol: $symbol,
                node: $constant,
                visibility: null,
                static: true,
                abstract: false,
                final: true,
                readonly: true,
                type: null,
                parameters: [],
                extends: [],
                implements: [],
                traits: [],
                attributes: $this->attributes($node->attrGroups),
                docNode: $node,
            );
        }
    }

    private function collectEnumCase(Stmt\EnumCase $node): void
    {
        $class = $this->currentClass();

        if ($class === null) {
            return;
        }

        $name = $node->name->toString();
        $this->declarations[] = $this->declaration(
            kind: 'enum_case',
            name: $name,
            symbol: $class.'::'.$name,
            node: $node,
            visibility: 'public',
            static: true,
            abstract: false,
            final: true,
            readonly: true,
            type: null,
            parameters: [],
            extends: [],
            implements: [],
            traits: [],
            attributes: $this->attributes($node->attrGroups),
        );
    }

    private function collectTraitUse(Stmt\TraitUse $node): void
    {
        $index = end($this->classDeclarationStack);

        if ($index === false || $index === null) {
            return;
        }

        $traits = array_map(fn (Name $name): string => $this->resolvedName($name), $node->traits);
        $this->declarations[$index]['traits'] = array_values(array_unique([
            ...$this->declarations[$index]['traits'],
            ...$traits,
        ]));
    }

    /** @param list<Node\Param> $parameters */
    private function collectPromotedProperties(string $class, array $parameters): void
    {
        foreach ($parameters as $parameter) {
            if (!$parameter->isPromoted() || !is_string($parameter->var->name)) {
                continue;
            }

            $name = $parameter->var->name;
            $this->declarations[] = $this->declaration(
                kind: 'property',
                name: $name,
                symbol: $class.'::$'.$name,
                node: $parameter,
                visibility: $this->promotedVisibility($parameter),
                static: false,
                abstract: false,
                final: $parameter->isFinal(),
                readonly: $parameter->isReadonly(),
                type: $this->type($parameter->type),
                parameters: [],
                extends: [],
                implements: [],
                traits: [],
                attributes: $this->attributes($parameter->attrGroups),
            );
        }
    }

    /**
     * @param list<Node\Param> $parameters
     * @return list<array{name:string,type:?string,by_reference:bool,variadic:bool,has_default:bool,promoted:?string,readonly:bool}>
     */
    private function parameters(array $parameters): array
    {
        $result = [];

        foreach ($parameters as $parameter) {
            $result[] = [
                'name' => is_string($parameter->var->name) ? $parameter->var->name : '',
                'type' => $this->type($parameter->type),
                'by_reference' => $parameter->byRef,
                'variadic' => $parameter->variadic,
                'has_default' => $parameter->default !== null,
                'promoted' => $parameter->isPromoted() ? $this->promotedVisibility($parameter) : null,
                'readonly' => $parameter->isReadonly(),
            ];
        }

        return $result;
    }

    /** @return list<string> */
    private function extends(Stmt\ClassLike $node): array
    {
        if ($node instanceof Stmt\Class_ && $node->extends !== null) {
            return [$this->resolvedName($node->extends)];
        }

        if ($node instanceof Stmt\Interface_) {
            return array_map(fn (Name $name): string => $this->resolvedName($name), $node->extends);
        }

        return [];
    }

    /** @return list<string> */
    private function implements(Stmt\ClassLike $node): array
    {
        if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Enum_) {
            return array_map(fn (Name $name): string => $this->resolvedName($name), $node->implements);
        }

        return [];
    }

    private function classKind(Stmt\ClassLike $node): string
    {
        return match (true) {
            $node instanceof Stmt\Interface_ => 'interface',
            $node instanceof Stmt\Trait_ => 'trait',
            $node instanceof Stmt\Enum_ => 'enum',
            default => 'class',
        };
    }

    /** @param list<Node\AttributeGroup> $groups @return list<string> */
    private function attributes(array $groups): array
    {
        $attributes = [];

        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                $attributes[] = $this->resolvedName($attribute->name);
            }
        }

        return array_values(array_unique($attributes));
    }

    private function type(Node\ComplexType|Node\Identifier|Name|null $type): ?string
    {
        return match (true) {
            $type === null => null,
            $type instanceof Node\Identifier => $type->toString(),
            $type instanceof Name => $this->resolvedName($type),
            $type instanceof Node\NullableType => '?'.$this->type($type->type),
            $type instanceof Node\UnionType => implode('|', array_map(fn ($item): string => (string) $this->type($item), $type->types)),
            $type instanceof Node\IntersectionType => implode('&', array_map(fn ($item): string => (string) $this->type($item), $type->types)),
            default => null,
        };
    }

    private function resolvedName(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return $resolved instanceof Name ? $resolved->toString() : $name->toString();
    }

    private function declarationName(Stmt\ClassLike|Stmt\Function_ $node): string
    {
        $namespaced = $node->namespacedName ?? null;

        if ($namespaced instanceof Name) {
            return $namespaced->toString();
        }

        $local = $node->name?->toString() ?? '';

        return $this->namespace !== null && $this->namespace !== '' ? $this->namespace.'\\'.$local : $local;
    }

    private function currentClass(): ?string
    {
        $class = end($this->classStack);

        return $class === false ? null : $class;
    }

    private function visibility(Stmt\ClassMethod|Stmt\Property|Stmt\ClassConst $node): string
    {
        return match (true) {
            $node->isPrivate() => 'private',
            $node->isProtected() => 'protected',
            default => 'public',
        };
    }

    private function promotedVisibility(Node\Param $parameter): string
    {
        return match (true) {
            $parameter->isPrivate() => 'private',
            $parameter->isProtected() => 'protected',
            default => 'public',
        };
    }

    private function useKind(int $type): string
    {
        return match ($type) {
            Stmt\Use_::TYPE_FUNCTION => 'function',
            Stmt\Use_::TYPE_CONSTANT => 'const',
            default => 'class',
        };
    }

    /**
     * @param list<array{name:string,type:?string,by_reference:bool,variadic:bool,has_default:bool,promoted:?string,readonly:bool}> $parameters
     * @param list<string> $extends
     * @param list<string> $implements
     * @param list<string> $traits
     * @param list<string> $attributes
     * @return array<string, mixed>
     */
    private function declaration(
        string $kind,
        string $name,
        string $symbol,
        Node $node,
        ?string $visibility,
        bool $static,
        bool $abstract,
        bool $final,
        bool $readonly,
        ?string $type,
        array $parameters,
        array $extends,
        array $implements,
        array $traits,
        array $attributes,
        ?Node $docNode = null,
    ): array {
        return [
            'kind' => $kind,
            'name' => $name,
            'symbol' => $symbol,
            'line' => $node->getStartLine(),
            'end_line' => $node->getEndLine(),
            'visibility' => $visibility,
            'static' => $static,
            'abstract' => $abstract,
            'final' => $final,
            'readonly' => $readonly,
            'type' => $type,
            'parameters' => $parameters,
            'extends' => $extends,
            'implements' => $implements,
            'traits' => $traits,
            'attributes' => $attributes,
            'doc' => $this->docSummary($docNode ?? $node),
        ];
    }

    private function docSummary(Node $node): ?string
    {
        $comment = $node->getDocComment();

        if ($comment === null) {
            return null;
        }

        $parts = [];

        foreach (preg_split('/\R/', $comment->getText()) ?: [] as $line) {
            $line = trim($line);
            $line = preg_replace('/^\/\*\*?\s?|\*\/\s?$|^\*\s?/', '', $line) ?? '';
            $line = trim($line);

            if ($line === '' && $parts !== []) {
                break;
            }

            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }

            $parts[] = $line;
        }

        if ($parts === []) {
            return null;
        }

        $summary = implode(' ', $parts);

        return strlen($summary) > 500 ? substr($summary, 0, 497).'...' : $summary;
    }
}
