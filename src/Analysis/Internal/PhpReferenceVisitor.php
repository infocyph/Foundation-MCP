<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Analysis\Internal;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/** @internal */
final class PhpReferenceVisitor extends NodeVisitorAbstract
{
    /** @var list<?string> */
    private array $classStack = [];

    /** @var list<array{relationship:string,target:string,line:int,confidence:string}> */
    private array $references = [];

    public function enterNode(Node $node)
    {
        if ($node instanceof Stmt\ClassLike) {
            $this->classStack[] = $this->className($node);
        }

        match (true) {
            $node instanceof Stmt\Class_ => $this->classReferences($node),
            $node instanceof Stmt\Interface_ => $this->interfaceReferences($node),
            $node instanceof Stmt\Enum_ => $this->enumReferences($node),
            $node instanceof Stmt\TraitUse => $this->traitReferences($node),
            $node instanceof Stmt\ClassMethod => $this->functionLikeReferences($node),
            $node instanceof Stmt\Function_ => $this->functionLikeReferences($node),
            $node instanceof Expr\Closure => $this->functionLikeReferences($node),
            $node instanceof Expr\ArrowFunction => $this->functionLikeReferences($node),
            $node instanceof Stmt\Property => $this->typedNodeReferences($node->type, $node->getStartLine()),
            $node instanceof Stmt\ClassConst => $this->typedNodeReferences($node->type, $node->getStartLine()),
            $node instanceof Node\Param => $this->typedNodeReferences($node->type, $node->getStartLine()),
            $node instanceof Node\Attribute => $this->reference('attribute', $this->resolvedName($node->name), $node->getStartLine(), 'resolved'),
            $node instanceof Expr\New_ => $this->newReference($node),
            $node instanceof Expr\StaticCall => $this->staticCallReference($node),
            $node instanceof Expr\FuncCall => $this->functionCallReference($node),
            $node instanceof Expr\MethodCall => $this->methodCallReference($node),
            $node instanceof Expr\ClassConstFetch => $this->classConstantReference($node),
            $node instanceof Expr\StaticPropertyFetch => $this->staticPropertyReference($node),
            $node instanceof Expr\PropertyFetch => $this->propertyReference($node),
            $node instanceof Expr\Instanceof_ => $this->instanceofReference($node),
            $node instanceof Stmt\Catch_ => $this->catchReferences($node),
            default => null,
        };

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Stmt\ClassLike) {
            array_pop($this->classStack);
        }

        return null;
    }

    /** @return list<array{relationship:string,target:string,line:int,confidence:string}> */
    public function references(): array
    {
        $seen = [];
        $references = [];

        foreach ($this->references as $reference) {
            $key = implode("\0", [
                $reference['relationship'],
                $reference['target'],
                (string) $reference['line'],
                $reference['confidence'],
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $references[] = $reference;
        }

        return $references;
    }

    private function classReferences(Stmt\Class_ $node): void
    {
        if ($node->extends !== null) {
            $this->reference('extends', $this->resolvedName($node->extends), $node->getStartLine(), 'resolved');
        }

        foreach ($node->implements as $interface) {
            $this->reference('implements', $this->resolvedName($interface), $node->getStartLine(), 'resolved');
        }
    }

    private function interfaceReferences(Stmt\Interface_ $node): void
    {
        foreach ($node->extends as $interface) {
            $this->reference('extends', $this->resolvedName($interface), $node->getStartLine(), 'resolved');
        }
    }

    private function enumReferences(Stmt\Enum_ $node): void
    {
        foreach ($node->implements as $interface) {
            $this->reference('implements', $this->resolvedName($interface), $node->getStartLine(), 'resolved');
        }
    }

    private function traitReferences(Stmt\TraitUse $node): void
    {
        foreach ($node->traits as $trait) {
            $this->reference('trait-use', $this->resolvedName($trait), $node->getStartLine(), 'resolved');
        }
    }

    private function functionLikeReferences(Stmt\ClassMethod|Stmt\Function_|Expr\Closure|Expr\ArrowFunction $node): void
    {
        $this->typedNodeReferences($node->returnType, $node->getStartLine());
    }

    private function newReference(Expr\New_ $node): void
    {
        if ($node->class instanceof Name) {
            [$target, $confidence] = $this->classReference($node->class);
            $this->reference('new', $target, $node->getStartLine(), $confidence);

            return;
        }

        $this->reference('new', '<dynamic>', $node->getStartLine(), 'dynamic');
    }

    private function staticCallReference(Expr\StaticCall $node): void
    {
        if ($node->class instanceof Name && $node->name instanceof Node\Identifier) {
            [$class, $confidence] = $this->classReference($node->class);
            $this->reference(
                'call',
                $class.'::'.$node->name->toString(),
                $node->getStartLine(),
                $confidence,
            );

            return;
        }

        $this->reference('call', '<dynamic>', $node->getStartLine(), 'dynamic');
    }

    private function functionCallReference(Expr\FuncCall $node): void
    {
        if ($node->name instanceof Name) {
            [$target, $confidence] = $this->nameReference($node->name);
            $this->reference('call', $target, $node->getStartLine(), $confidence);

            return;
        }

        $this->reference('call', '<dynamic>', $node->getStartLine(), 'dynamic');
    }

    private function methodCallReference(Expr\MethodCall $node): void
    {
        if (!$node->name instanceof Node\Identifier) {
            $this->reference('call', '<dynamic>', $node->getStartLine(), 'dynamic');

            return;
        }

        $method = $node->name->toString();

        if ($node->var instanceof Expr\Variable && $node->var->name === 'this' && ($class = $this->currentClass()) !== null) {
            $this->reference('call', $class.'::'.$method, $node->getStartLine(), 'resolved');

            return;
        }

        $this->reference('call', $method, $node->getStartLine(), 'lexical');
    }

    private function classConstantReference(Expr\ClassConstFetch $node): void
    {
        if ($node->class instanceof Name && $node->name instanceof Node\Identifier) {
            [$class, $confidence] = $this->classReference($node->class);
            $this->reference(
                'class_constant',
                $class.'::'.$node->name->toString(),
                $node->getStartLine(),
                $confidence,
            );

            return;
        }

        $this->reference('class_constant', '<dynamic>', $node->getStartLine(), 'dynamic');
    }

    private function staticPropertyReference(Expr\StaticPropertyFetch $node): void
    {
        if ($node->class instanceof Name && $node->name instanceof Node\VarLikeIdentifier) {
            [$class, $confidence] = $this->classReference($node->class);
            $this->reference(
                'property',
                $class.'::$'.$node->name->toString(),
                $node->getStartLine(),
                $confidence,
            );

            return;
        }

        $this->reference('property', '<dynamic>', $node->getStartLine(), 'dynamic');
    }

    private function propertyReference(Expr\PropertyFetch $node): void
    {
        if (!$node->name instanceof Node\Identifier) {
            $this->reference('property', '<dynamic>', $node->getStartLine(), 'dynamic');

            return;
        }

        $property = $node->name->toString();

        if ($node->var instanceof Expr\Variable && $node->var->name === 'this' && ($class = $this->currentClass()) !== null) {
            $this->reference('property', $class.'::$'.$property, $node->getStartLine(), 'resolved');

            return;
        }

        $this->reference('property', $property, $node->getStartLine(), 'lexical');
    }

    private function instanceofReference(Expr\Instanceof_ $node): void
    {
        if ($node->class instanceof Name) {
            [$target, $confidence] = $this->classReference($node->class);
            $this->reference('type', $target, $node->getStartLine(), $confidence);

            return;
        }

        $this->reference('type', '<dynamic>', $node->getStartLine(), 'dynamic');
    }

    private function catchReferences(Stmt\Catch_ $node): void
    {
        foreach ($node->types as $type) {
            $this->reference('type', $this->resolvedName($type), $node->getStartLine(), 'resolved');
        }
    }

    private function typedNodeReferences(Node\ComplexType|Node\Identifier|Name|null $type, int $line): void
    {
        if ($type instanceof Name) {
            $this->reference('type', $this->resolvedName($type), $line, 'resolved');

            return;
        }

        if ($type instanceof Node\NullableType) {
            $this->typedNodeReferences($type->type, $line);

            return;
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $item) {
                $this->typedNodeReferences($item, $line);
            }
        }
    }

    private function className(Stmt\ClassLike $node): ?string
    {
        if ($node->name === null) {
            return null;
        }

        $namespaced = $node->namespacedName ?? null;

        return $namespaced instanceof Name ? $namespaced->toString() : $node->name->toString();
    }

    private function currentClass(): ?string
    {
        $class = end($this->classStack);

        return $class === false ? null : $class;
    }

    /** @return array{0:string,1:string} */
    private function classReference(Name $name): array
    {
        return match (strtolower($name->toString())) {
            'self' => [$this->currentClass() ?? 'self', $this->currentClass() !== null ? 'resolved' : 'lexical'],
            'static' => [$this->currentClass() ?? 'static', 'lexical'],
            'parent' => ['parent', 'lexical'],
            default => [$this->resolvedName($name), 'resolved'],
        };
    }

    /** @return array{0:string,1:string} */
    private function nameReference(Name $name): array
    {
        $resolved = $name->getAttribute('resolvedName');

        if ($resolved instanceof Name) {
            return [$resolved->toString(), 'resolved'];
        }

        $namespaced = $name->getAttribute('namespacedName');

        if ($namespaced instanceof Name) {
            return [$namespaced->toString(), 'lexical'];
        }

        return [$name->toString(), 'lexical'];
    }

    private function resolvedName(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return $resolved instanceof Name ? $resolved->toString() : $name->toString();
    }

    private function reference(string $relationship, string $target, int $line, string $confidence): void
    {
        $this->references[] = compact('relationship', 'target', 'line', 'confidence');
    }
}
