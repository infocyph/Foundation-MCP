<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation;

use Infocyph\FoundationMcp\Analysis\Internal\PhpNodeBudgetVisitor;
use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Foundation\Internal\RouteValueResolver;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Security\PathPolicy;
use Infocyph\FoundationMcp\Security\Redactor;
use Infocyph\FoundationMcp\Security\SecretPolicy;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;

/**
 * Static inspector for Foundation application-maintenance workers.
 *
 * This category is intentionally limited to routes/workers.php. Messaging
 * consumers configured through Omnibus belong to OmnibusWorkerInspector.
 */
final class FoundationWorkerInspector
{
    private const int MAX_DIAGNOSTICS = 100;

    private const int MAX_OPTION_DEPTH = 32;

    private const int MAX_OPTION_ITEMS = 64;

    private const int MAX_RECURSION_DEPTH = 64;

    private const int MAX_SOURCE_BYTES = 1_048_576;

    private const int MAX_WORKERS = 500;

    private const string SOURCE = 'routes/workers.php';

    private readonly Parser $parser;

    private readonly PathPolicy $paths;

    private readonly Redactor $redactor;

    private readonly SecretPolicy $secrets;

    private readonly RouteValueResolver $values;

    /** @var list<array{code:string,source:?string,line:?int,message:string}> */
    private array $diagnostics = [];

    /** @var list<array{name:?string,handler:?string,registration:string,options:array<string,mixed>,source:string,line:int,status:string,conditional:bool}> */
    private array $workers = [];

    public function __construct(
        private readonly Project $project,
        private readonly ComposerInspector $composer,
        ?Parser $parser = null,
    ) {
        $this->parser = $parser ?? new ParserFactory()->createForNewestSupportedVersion();
        $this->paths = new PathPolicy($project->root);
        $this->secrets = new SecretPolicy();
        $this->redactor = new Redactor();
        $this->values = new RouteValueResolver();
    }

    /**
     * @return array{
     *   source:string,category:string,foundation_version:?string,contract_status:string,
     *   workers:list<array{name:?string,handler:?string,registration:string,options:array<string,mixed>,source:string,line:int,status:string,conditional:bool}>,
     *   diagnostics:list<array{code:string,source:?string,line:?int,message:string}>
     * }
     */
    public function inspect(): array
    {
        $this->diagnostics = [];
        $this->workers = [];
        $foundation = $this->composer->foundation();
        $version = $foundation?->installedVersion ?? $foundation?->lockedVersion;
        $candidate = $this->project->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::SOURCE);

        if (!is_file($candidate)) {
            return $this->result($version, 'source_absent');
        }

        try {
            $this->secrets->assertAllowed(self::SOURCE);
            $path = $this->paths->projectFile(self::SOURCE);
        } catch (RuntimeException $error) {
            $this->diagnostic('worker_source_invalid', null, $error->getMessage());

            return $this->result($version, 'invalid_source');
        }

        $nodes = $this->parse($path);
        if ($nodes === null) {
            return $this->result($version, 'parse_error');
        }

        $return = $this->topLevelReturn($nodes);
        if ($return?->expr instanceof Node\Expr\Array_) {
            $this->scanArray($return->expr, false);

            return $this->result($version, 'static_array');
        }

        if ($return?->expr instanceof Node\Expr\Closure || $return?->expr instanceof Node\Expr\ArrowFunction) {
            $callback = $return->expr;
            $parameter = $callback->params[0] ?? null;
            $variable = $parameter instanceof Node\Param && $parameter->var instanceof Node\Expr\Variable && is_string($parameter->var->name)
                ? $parameter->var->name
                : null;
            $contract = $this->providerContract($parameter) ? 'worker_provider' : 'callable_unverified';
            if ($variable === null) {
                $this->diagnostic('worker_contract_dynamic', $return->getStartLine(), 'Worker callback has no statically identifiable provider parameter.');

                return $this->result($version, 'callable_unverified');
            }
            $statements = $callback instanceof Node\Expr\Closure
                ? $callback->stmts
                : [new Node\Stmt\Expression($callback->expr)];
            $this->scanStatements($statements, $variable, false);

            return $this->result($version, $contract);
        }

        $this->diagnostic('worker_contract_dynamic', $return?->getStartLine(), 'routes/workers.php is not a statically inspectable worker array or provider callback.');

        return $this->result($version, 'dynamic');
    }

    private function diagnostic(string $code, ?int $line, string $message): void
    {
        $count = count($this->diagnostics);
        if ($count >= self::MAX_DIAGNOSTICS) {
            return;
        }
        if ($count === self::MAX_DIAGNOSTICS - 1 && $code !== 'diagnostic_limit_exceeded') {
            $this->diagnostics[] = [
                'code' => 'diagnostic_limit_exceeded',
                'source' => self::SOURCE,
                'line' => null,
                'message' => sprintf('Foundation worker diagnostics are limited to %d entries.', self::MAX_DIAGNOSTICS),
            ];

            return;
        }

        $this->diagnostics[] = ['code' => $code, 'source' => self::SOURCE, 'line' => $line, 'message' => $message];
    }

    private function diagnosticOnce(string $code, ?int $line, string $message): void
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic['code'] === $code) {
                return;
            }
        }

        $this->diagnostic($code, $line, $message);
    }

    /** @return list<Node\Stmt>|null */
    private function parse(string $path): ?array
    {
        $source = $this->read($path);
        if ($source === null) {
            $this->diagnostic('worker_source_unreadable', null, sprintf(
                'Worker source is unreadable, binary, invalid UTF-8, or exceeds %d bytes.',
                self::MAX_SOURCE_BYTES,
            ));

            return null;
        }

        try {
            $nodes = $this->parser->parse($source) ?? [];
        } catch (Error $error) {
            $this->diagnostic('parse_error', $error->getStartLine(), $error->getRawMessage());

            return null;
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new PhpNodeBudgetVisitor());
        $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]));

        try {
            /** @var list<Node\Stmt> $resolved */
            $resolved = $traverser->traverse($nodes);

            return $resolved;
        } catch (Error $error) {
            $this->diagnostic('parse_error', $error->getStartLine(), $error->getRawMessage());
        } catch (RuntimeException $error) {
            $this->diagnostic('inspection_limit_exceeded', null, $error->getMessage());
        }

        return null;
    }

    private function providerContract(?Node\Param $parameter): bool
    {
        $type = $parameter?->type;
        if (!$type instanceof Node\Name) {
            return false;
        }
        $resolved = $this->values->resolvedName($type);

        return str_ends_with(strtolower($resolved), '\\workerprovider') || strtolower($resolved) === 'workerprovider';
    }

    private function read(string $path): ?string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $source = stream_get_contents($handle, self::MAX_SOURCE_BYTES + 1);
        } finally {
            fclose($handle);
        }

        if (
            !is_string($source)
            || strlen($source) > self::MAX_SOURCE_BYTES
            || str_contains($source, "\0")
            || preg_match('//u', $source) !== 1
        ) {
            return null;
        }

        return $source;
    }

    private function result(?string $version, string $contract): array
    {
        usort($this->workers, static fn(array $a, array $b): int => [$a['line'], $a['name'] ?? '', $a['handler'] ?? ''] <=> [$b['line'], $b['name'] ?? '', $b['handler'] ?? '']);

        return [
            'source' => self::SOURCE,
            'category' => 'foundation_maintenance',
            'foundation_version' => $version,
            'contract_status' => $contract,
            'workers' => $this->workers,
            'diagnostics' => $this->diagnostics,
        ];
    }

    private function sanitizeOptions(array $options, int $depth = 0): array
    {
        if ($depth >= self::MAX_OPTION_DEPTH) {
            $this->diagnosticOnce(
                'worker_option_depth_exceeded',
                null,
                sprintf('Foundation worker option redaction is limited to %d levels.', self::MAX_OPTION_DEPTH),
            );

            return [];
        }
        if (count($options) > self::MAX_OPTION_ITEMS) {
            $this->diagnosticOnce(
                'worker_option_limit_exceeded',
                null,
                sprintf('Foundation worker option objects are limited to %d entries per level.', self::MAX_OPTION_ITEMS),
            );
            $options = array_slice($options, 0, self::MAX_OPTION_ITEMS, true);
        }

        $result = [];
        foreach ($options as $key => $value) {
            if (is_string($key) && preg_match('~(?:password|secret|token|api[_-]?key|private[_-]?key|authorization|cookie|credential|dsn)~i', $key) === 1) {
                $result[$key] = '[REDACTED]';

                continue;
            }
            if (is_array($value)) {
                $result[$key] = $this->sanitizeOptions($value, $depth + 1);

                continue;
            }
            $result[$key] = is_string($value) ? $this->redactor->redact($value) : $value;
        }

        return $result;
    }

    private function scanArray(Node\Expr\Array_ $array, bool $conditional): void
    {
        foreach ($array->items as $item) {
            if (count($this->workers) >= self::MAX_WORKERS) {
                $this->diagnostic('output_limit_exceeded', $array->getStartLine(), sprintf('Foundation workers are limited to %d entries.', self::MAX_WORKERS));

                return;
            }
            if (!$item instanceof Node\Expr\ArrayItem || $item->unpack) {
                $this->diagnostic('worker_dynamic', $array->getStartLine(), 'Worker array contains dynamic or unpacked syntax.');

                continue;
            }
            $name = $item->key instanceof Node\Expr ? $this->values->stringValue($item->key) : null;
            $handler = $this->values->classString($item->value);
            $options = [];
            if ($handler === null && $item->value instanceof Node\Expr\Array_) {
                $literal = $this->values->literalArrayValue($item->value);
                if (is_array($literal)) {
                    $name = is_string($literal['name'] ?? null) ? $literal['name'] : $name;
                    foreach (['handler', 'class', 'worker'] as $key) {
                        if (is_string($literal[$key] ?? null)) {
                            $handler = $literal[$key];

                            break;
                        }
                    }
                    $options = is_array($literal['options'] ?? null) ? $literal['options'] : $literal;
                    unset($options['name'], $options['handler'], $options['class'], $options['worker']);
                }
            }
            $this->workers[] = $this->worker($name, $handler, 'array', $options, $item->getStartLine(), $conditional);
        }
    }

    private function scanExpression(Node\Expr $expr, string $provider, bool $conditional): void
    {
        if (!$expr instanceof Node\Expr\MethodCall || !$expr->var instanceof Node\Expr\Variable || $expr->var->name !== $provider || !$expr->name instanceof Node\Identifier) {
            return;
        }
        $args = $this->values->callArgs($expr);
        $first = $this->values->arg($args, 0, 'worker') ?? $this->values->arg($args, 0, 'name');
        $second = $this->values->arg($args, 1, 'handler') ?? $this->values->arg($args, 1, 'class');
        $firstString = $first instanceof Node\Expr ? $this->values->stringValue($first) : null;
        $firstClass = $first instanceof Node\Expr ? $this->values->classString($first) : null;
        $secondClass = $second instanceof Node\Expr ? $this->values->classString($second) : null;
        $name = $secondClass !== null ? $firstString : null;
        $handler = $secondClass ?? $firstClass;
        $optionsExpr = $this->values->arg($args, $secondClass !== null ? 2 : 1, 'options');
        $options = $optionsExpr instanceof Node\Expr ? ($this->values->literalArrayValue($optionsExpr) ?? []) : [];
        $this->workers[] = $this->worker($name, $handler, strtolower($expr->name->toString()), $options, $expr->getStartLine(), $conditional);
    }

    /** @param list<Node\Stmt> $statements */
    private function scanStatements(array $statements, string $provider, bool $conditional, int $depth = 0): void
    {
        if ($depth >= self::MAX_RECURSION_DEPTH) {
            $this->diagnosticOnce(
                'worker_traversal_limit_exceeded',
                null,
                sprintf('Foundation worker statement traversal is limited to %d levels.', self::MAX_RECURSION_DEPTH),
            );

            return;
        }

        foreach ($statements as $statement) {
            if (count($this->workers) >= self::MAX_WORKERS) {
                $this->diagnostic('output_limit_exceeded', $statement->getStartLine(), sprintf('Foundation workers are limited to %d entries.', self::MAX_WORKERS));

                return;
            }
            if ($statement instanceof Node\Stmt\Expression) {
                $this->scanExpression($statement->expr, $provider, $conditional);

                continue;
            }
            if ($statement instanceof Node\Stmt\If_) {
                $this->scanStatements($statement->stmts, $provider, true, $depth + 1);
                foreach ($statement->elseifs as $elseif) {
                    $this->scanStatements($elseif->stmts, $provider, true, $depth + 1);
                }
                if ($statement->else !== null) {
                    $this->scanStatements($statement->else->stmts, $provider, true, $depth + 1);
                }

                continue;
            }
            if ($statement instanceof Node\Stmt\Foreach_ || $statement instanceof Node\Stmt\For_ || $statement instanceof Node\Stmt\While_ || $statement instanceof Node\Stmt\Do_) {
                $this->scanStatements($statement->stmts, $provider, true, $depth + 1);
            }
        }
    }

    /** @param list<Node\Stmt> $nodes */
    private function topLevelReturn(array $nodes): ?Node\Stmt\Return_
    {
        foreach ($nodes as $node) {
            foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $statement) {
                if ($statement instanceof Node\Stmt\Return_) {
                    return $statement;
                }
            }
        }

        return null;
    }

    /** @return array{name:?string,handler:?string,registration:string,options:array<string,mixed>,source:string,line:int,status:string,conditional:bool} */
    private function worker(?string $name, ?string $handler, string $registration, array $options, int $line, bool $conditional): array
    {
        return [
            'name' => $name,
            'handler' => $handler,
            'registration' => $registration,
            'options' => $this->sanitizeOptions($options),
            'source' => self::SOURCE,
            'line' => $line,
            'status' => $handler === null ? 'dynamic' : 'resolved',
            'conditional' => $conditional,
        ];
    }
}
