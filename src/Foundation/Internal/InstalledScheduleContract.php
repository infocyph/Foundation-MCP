<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation\Internal;

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Security\PathPolicy;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use RuntimeException;

/** @phpstan-type Diagnostic array{code:string,source:?string,line:?int,message:string} */
final class InstalledScheduleContract
{
    private const int MAX_SOURCE_BYTES = 1_048_576;

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    public function __construct(
        private readonly Project $project,
        private readonly ComposerInspector $composer,
        private readonly Parser $parser,
    ) {}

    /** @return array{route_file:?string,fluent_methods:array<string,true>,diagnostics:list<Diagnostic>} */
    public function read(): array
    {
        $this->diagnostics = [];
        $manager = $this->source('src/Scheduling/ScheduleManager.php');
        $command = $this->source('src/Scheduling/ScheduledCommand.php');
        if ($manager === null || $command === null) {
            return ['route_file' => null, 'fluent_methods' => [], 'diagnostics' => $this->diagnostics];
        }

        $managerNodes = $this->parse($manager, 'infocyph/foundation:src/Scheduling/ScheduleManager.php');
        $commandNodes = $this->parse($command, 'infocyph/foundation:src/Scheduling/ScheduledCommand.php');
        if ($managerNodes === null || $commandNodes === null) {
            return ['route_file' => null, 'fluent_methods' => [], 'diagnostics' => $this->diagnostics];
        }

        return [
            'route_file' => $this->routeFile($managerNodes),
            'fluent_methods' => $this->fluentMethods($commandNodes),
            'diagnostics' => $this->diagnostics,
        ];
    }

    /** @param list<Node\Stmt> $nodes */
    private function routeFile(array $nodes): ?string
    {
        foreach ($this->classes($nodes) as $class) {
            foreach ($class->getMethods() as $method) {
                if ($method->name->toString() !== 'configured') {
                    continue;
                }
                $default = $method->params[0]->default ?? null;
                if ($default instanceof Node\Scalar\String_ && $default->value !== '') {
                    return $default->value;
                }
            }
        }
        $this->diagnostic('schedule_contract_invalid', 'infocyph/foundation:src/Scheduling/ScheduleManager.php', null, 'Installed schedule route path could not be derived.');
        return null;
    }

    /** @param list<Node\Stmt> $nodes @return array<string,true> */
    private function fluentMethods(array $nodes): array
    {
        $methods = [];
        foreach ($this->classes($nodes) as $class) {
            foreach ($class->getMethods() as $method) {
                if (!$method->isPublic() || !$method->returnType instanceof Node\Name || strtolower($method->returnType->toString()) !== 'self') {
                    continue;
                }
                $methods[strtolower($method->name->toString())] = true;
            }
        }
        ksort($methods, SORT_STRING);
        return $methods;
    }

    private function source(string $relative): ?string
    {
        $root = $this->composer->packageRoots(['infocyph/foundation'])['infocyph/foundation'] ?? null;
        if ($root === null) {
            $this->diagnostic('schedule_contract_missing', null, null, 'Installed Foundation source root is unavailable.');
            return null;
        }
        try {
            return (new PathPolicy($this->project->root, ['infocyph/foundation' => $root]))->packageFile('infocyph/foundation', $relative);
        } catch (RuntimeException $error) {
            $this->diagnostic('schedule_contract_invalid', 'infocyph/foundation:'.$relative, null, $error->getMessage());
            return null;
        }
    }

    /** @return list<Node\Stmt>|null */
    private function parse(string $path, string $source): ?array
    {
        $size = filesize($path);
        if ($size === false || $size > self::MAX_SOURCE_BYTES) {
            $this->diagnostic('source_too_large', $source, null, sprintf('Schedule contract source exceeds %d bytes.', self::MAX_SOURCE_BYTES));
            return null;
        }
        $contents = file_get_contents($path);
        if (!is_string($contents) || str_contains($contents, "\0")) {
            $this->diagnostic('source_unreadable', $source, null, 'Schedule contract source is unreadable or binary.');
            return null;
        }
        try {
            $nodes = $this->parser->parse($contents) ?? [];
        } catch (Error $error) {
            $this->diagnostic('parse_error', $source, $error->getStartLine(), $error->getMessage());
            return null;
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]));
        return $traverser->traverse($nodes);
    }

    /** @param list<Node\Stmt> $nodes @return list<Node\Stmt\Class_> */
    private function classes(array $nodes): array
    {
        $classes = [];
        foreach ($nodes as $node) {
            foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $statement) {
                if ($statement instanceof Node\Stmt\Class_) {
                    $classes[] = $statement;
                }
            }
        }
        return $classes;
    }

    private function diagnostic(string $code, ?string $source, ?int $line, string $message): void
    {
        $this->diagnostics[] = compact('code', 'source', 'line', 'message');
    }
}
