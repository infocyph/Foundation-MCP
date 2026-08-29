<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation\Internal;

use Infocyph\FoundationMcp\Analysis\Internal\PhpNodeBudgetVisitor;
use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Security\PathPolicy;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;

/** @phpstan-type Diagnostic array{code:string,source:?string,line:?int,message:string} */
final readonly class InstalledRuntimeContract
{
    private const int MAX_SOURCE_BYTES = 262_144;

    private Parser $parser;

    public function __construct(
        private Project $project,
        private ComposerInspector $composer,
        ?Parser $parser = null,
    ) {
        $this->parser = $parser ?? new ParserFactory()->createForNewestSupportedVersion();
    }

    /** @return array{methods:array<string,string>,preset:bool,diagnostics:list<Diagnostic>} */
    public function read(): array
    {
        $package = $this->composer->package('infocyph/foundation');
        if ($package?->installPath === null) {
            return ['methods' => [], 'preset' => false, 'diagnostics' => [[
                'code' => 'runtime_contract_missing',
                'source' => null,
                'line' => null,
                'message' => 'Installed Foundation runtime contract is unavailable.',
            ]]];
        }

        try {
            $paths = new PathPolicy($this->project->root, $this->composer->packageRoots(['infocyph/foundation']));
            $path = $paths->packageFile('infocyph/foundation', 'src/Foundation.php');
        } catch (RuntimeException $error) {
            return ['methods' => [], 'preset' => false, 'diagnostics' => [[
                'code' => 'runtime_contract_missing',
                'source' => 'infocyph/foundation:src/Foundation.php',
                'line' => null,
                'message' => $error->getMessage(),
            ]]];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['methods' => [], 'preset' => false, 'diagnostics' => [[
                'code' => 'runtime_contract_invalid',
                'source' => 'infocyph/foundation:src/Foundation.php',
                'line' => null,
                'message' => 'Installed Foundation runtime contract is unreadable.',
            ]]];
        }

        try {
            $contents = stream_get_contents($handle, self::MAX_SOURCE_BYTES + 1);
        } finally {
            fclose($handle);
        }

        if (!is_string($contents) || str_contains($contents, "\0")) {
            return ['methods' => [], 'preset' => false, 'diagnostics' => [[
                'code' => 'runtime_contract_invalid',
                'source' => 'infocyph/foundation:src/Foundation.php',
                'line' => null,
                'message' => 'Installed Foundation runtime contract is unreadable or binary.',
            ]]];
        }
        if (strlen($contents) > self::MAX_SOURCE_BYTES) {
            return ['methods' => [], 'preset' => false, 'diagnostics' => [[
                'code' => 'runtime_contract_invalid',
                'source' => 'infocyph/foundation:src/Foundation.php',
                'line' => null,
                'message' => 'Installed Foundation runtime contract exceeds the inspection limit.',
            ]]];
        }

        try {
            $nodes = $this->parser->parse($contents) ?? [];
        } catch (Error $error) {
            return ['methods' => [], 'preset' => false, 'diagnostics' => [[
                'code' => 'parse_error',
                'source' => 'infocyph/foundation:src/Foundation.php',
                'line' => $error->getStartLine(),
                'message' => $error->getMessage(),
            ]]];
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]));
        $traverser->addVisitor(new PhpNodeBudgetVisitor());

        try {
            $nodes = $traverser->traverse($nodes);
        } catch (RuntimeException $error) {
            return ['methods' => [], 'preset' => false, 'diagnostics' => [[
                'code' => 'runtime_contract_too_complex',
                'source' => 'infocyph/foundation:src/Foundation.php',
                'line' => null,
                'message' => $error->getMessage(),
            ]]];
        }

        $methods = [];
        $preset = false;
        foreach ($this->classes($nodes) as $class) {
            if (($class->namespacedName?->toString() ?? '') !== 'Infocyph\\Foundation\\Foundation') {
                continue;
            }
            foreach ($class->getMethods() as $method) {
                if (!$method->isPublic() || !$method->isStatic()) {
                    continue;
                }
                $name = strtolower($method->name->toString());
                if ($name === 'preset') {
                    $preset = true;

                    continue;
                }
                $mode = $this->runtimeMode($method);
                if ($mode !== null) {
                    $methods[$name] = $mode;
                }
            }
        }
        ksort($methods, SORT_STRING);

        return ['methods' => $methods, 'preset' => $preset, 'diagnostics' => []];
    }

    /** @param list<Node\Stmt> $nodes @return list<Node\Stmt\Class_> */
    private function classes(array $nodes): array
    {
        $classes = [];
        foreach ($nodes as $node) {
            $statements = $node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node];
            foreach ($statements as $statement) {
                if ($statement instanceof Node\Stmt\Class_) {
                    $classes[] = $statement;
                }
            }
        }

        return $classes;
    }

    private function runtimeMode(Node\Stmt\ClassMethod $method): ?string
    {
        foreach ($method->stmts ?? [] as $statement) {
            if (!$statement instanceof Node\Stmt\Return_ || !$statement->expr instanceof Node\Expr\StaticCall) {
                continue;
            }
            $call = $statement->expr;
            if (!$call->name instanceof Node\Identifier || strtolower($call->name->toString()) !== 'createfor') {
                continue;
            }
            $arg = $call->args[0]->value ?? null;
            if (!$arg instanceof Node\Expr\ClassConstFetch || !$arg->class instanceof Node\Name || !$arg->name instanceof Node\Identifier) {
                continue;
            }
            $class = $arg->class->getAttribute('resolvedName');
            $class = $class instanceof Node\Name ? $class->toString() : $arg->class->toString();
            if ($class !== 'Infocyph\\Foundation\\Application\\RuntimeMode') {
                continue;
            }

            return strtolower($arg->name->toString());
        }

        return null;
    }
}
