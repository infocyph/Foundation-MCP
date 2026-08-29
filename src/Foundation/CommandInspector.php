<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation;

use Infocyph\FoundationMcp\Analysis\Internal\PhpNodeBudgetVisitor;
use Infocyph\FoundationMcp\Analysis\SymbolIndex;
use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Foundation\Internal\CommandDefinitionScanner;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Security\PathPolicy;
use Infocyph\FoundationMcp\Security\SecretPolicy;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;

/**
 * Static inspection of explicit routes/console.php application command registration.
 *
 * @phpstan-type CommandEntry array{
 *   name:?string,route_name:?string,handler:?string,source:string,line:int,handler_source:?string,
 *   metadata:array<string,mixed>,status:string,dynamic_fields:list<string>
 * }
 * @phpstan-type Diagnostic array{code:string,source:?string,line:?int,message:string}
 */
final class CommandInspector
{
    private const int MAX_COMMANDS = 1_000;

    private const int MAX_DIAGNOSTICS = 100;

    private const int MAX_IDENTIFIER_BYTES = 1_024;

    private const int MAX_ROUTE_NAME_BYTES = 512;

    private const int MAX_SOURCE_BYTES = 1_048_576;

    private const string SOURCE = 'routes/console.php';

    private readonly CommandDefinitionScanner $definitions;

    private readonly Parser $parser;

    private readonly PathPolicy $paths;

    private readonly SecretPolicy $secrets;

    private readonly SymbolIndex $symbols;

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    public function __construct(
        private readonly Project $project,
        ComposerInspector $composer,
        ?Parser $parser = null,
        ?SymbolIndex $symbols = null,
        ?CommandDefinitionScanner $definitions = null,
    ) {
        $this->parser = $parser ?? new ParserFactory()->createForNewestSupportedVersion();
        $this->paths = new PathPolicy($project->root);
        $this->secrets = new SecretPolicy();
        $this->symbols = $symbols ?? new SymbolIndex($project, $composer);
        $this->definitions = $definitions ?? new CommandDefinitionScanner();
    }

    /** @return array{source:string,commands:list<CommandEntry>,diagnostics:list<Diagnostic>} */
    public function inspect(): array
    {
        $this->diagnostics = [];
        $candidate = $this->project->root . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'console.php';
        if (!is_file($candidate)) {
            return ['source' => self::SOURCE, 'commands' => [], 'diagnostics' => []];
        }

        try {
            $nodes = $this->parse($this->paths->projectFile(self::SOURCE), self::SOURCE);
        } catch (RuntimeException $error) {
            $this->diagnostic('command_source_invalid', self::SOURCE, null, $error->getMessage());

            return ['source' => self::SOURCE, 'commands' => [], 'diagnostics' => $this->diagnostics];
        }

        if ($nodes === null) {
            return ['source' => self::SOURCE, 'commands' => [], 'diagnostics' => $this->diagnostics];
        }

        $array = $this->returnedArray($nodes);
        if (!$array instanceof Node\Expr\Array_) {
            $this->diagnostic('dynamic_unresolved', self::SOURCE, null, 'Application command registration is not a statically inspectable returned array.');

            return ['source' => self::SOURCE, 'commands' => [], 'diagnostics' => $this->diagnostics];
        }

        $commands = [];
        foreach ($array->items as $item) {
            if (count($commands) >= self::MAX_COMMANDS) {
                $this->diagnostic('command_limit_exceeded', self::SOURCE, null, sprintf('Command inspection is limited to %d entries.', self::MAX_COMMANDS));

                break;
            }
            if (!$item instanceof Node\Expr\ArrayItem || $item->unpack) {
                $this->diagnostic('dynamic_unresolved', self::SOURCE, $item?->getStartLine(), 'Unsupported command registration array syntax.');

                continue;
            }
            $commands[] = $this->entry($item);
        }

        $this->detectConflicts($commands);
        usort($commands, static fn(array $left, array $right): int => [
            $left['name'] ?? '', $left['handler'] ?? '', $left['line'],
        ] <=> [
            $right['name'] ?? '', $right['handler'] ?? '', $right['line'],
        ]);
        usort($this->diagnostics, static fn(array $left, array $right): int => [
            $left['source'] ?? '', $left['line'] ?? 0, $left['code'], $left['message'],
        ] <=> [
            $right['source'] ?? '', $right['line'] ?? 0, $right['code'], $right['message'],
        ]);

        return ['source' => self::SOURCE, 'commands' => $commands, 'diagnostics' => $this->diagnostics];
    }

    /** @param list<Node\Stmt> $nodes */
    private function classNode(array $nodes, string $handler): ?Node\Stmt\Class_
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                $class = $this->classNode($node->stmts, $handler);
                if ($class !== null) {
                    return $class;
                }

                continue;
            }
            if (!$node instanceof Node\Stmt\Class_) {
                continue;
            }
            $name = $node->namespacedName?->toString() ?? $node->name?->toString();
            if ($name === $handler) {
                return $node;
            }
        }

        return null;
    }

    /** @param list<CommandEntry> $commands */
    private function detectConflicts(array $commands): void
    {
        $seen = [];
        foreach ($commands as $command) {
            $names = [];
            is_string($command['name']) && $command['name'] !== '' && $names[] = $command['name'];
            foreach ($command['metadata']['aliases'] ?? [] as $alias) {
                is_string($alias) && $alias !== '' && $names[] = $alias;
            }
            foreach ($names as $name) {
                if (isset($seen[$name])) {
                    $this->diagnostic('command_duplicate', self::SOURCE, $command['line'], sprintf('Command route or alias "%s" is registered more than once.', $name));
                } else {
                    $seen[$name] = true;
                }
            }
        }
    }

    private function diagnostic(string $code, ?string $source, ?int $line, string $message): void
    {
        if (count($this->diagnostics) < self::MAX_DIAGNOSTICS) {
            $this->diagnostics[] = [
                'code' => $code,
                'source' => $source,
                'line' => $line,
                'message' => $message,
            ];

            return;
        }

        $this->diagnostics[self::MAX_DIAGNOSTICS - 1] = [
            'code' => 'diagnostics_truncated',
            'source' => null,
            'line' => null,
            'message' => sprintf('Command diagnostics are limited to %d entries.', self::MAX_DIAGNOSTICS),
        ];
    }

    /** @return array<string,mixed> */
    private function emptyMetadata(): array
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

    /** @return CommandEntry */
    private function entry(Node\Expr\ArrayItem $item): array
    {
        $dynamic = [];
        $route = $this->routeName($item->key);
        if ($item->key !== null && $route === null) {
            $dynamic[] = 'route_name';
        }

        $handler = $this->handler($item->value);
        if ($handler === null) {
            $dynamic[] = 'handler';
        }

        $metadata = $this->emptyMetadata();
        $handlerSource = null;
        if ($handler !== null) {
            [$metadata, $handlerSource] = $this->metadata($handler);
        }

        $definedName = is_string($metadata['name'] ?? null) ? $metadata['name'] : null;
        $name = $route ?? $definedName;
        if ($name === null) {
            $dynamic[] = 'name';
        }
        if ($route !== null && $definedName !== null && $route !== $definedName) {
            $this->diagnostic(
                'command_route_mismatch',
                self::SOURCE,
                $item->getStartLine(),
                sprintf('Command route "%s" does not match %s::define() name "%s".', $route, $handler ?? 'handler', $definedName),
            );
        }

        $dynamic = $this->unique([...$dynamic, ...array_map(
            static fn(string $field): string => 'metadata.' . $field,
            is_array($metadata['dynamic_fields'] ?? null) ? $metadata['dynamic_fields'] : [],
        )]);

        return [
            'name' => $name,
            'route_name' => $route,
            'handler' => $handler,
            'source' => self::SOURCE,
            'line' => $item->getStartLine(),
            'handler_source' => $handlerSource,
            'metadata' => $metadata,
            'status' => $dynamic === [] ? 'resolved' : 'dynamic',
            'dynamic_fields' => $dynamic,
        ];
    }

    private function handler(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return strlen($expr->value) <= self::MAX_IDENTIFIER_BYTES ? ltrim($expr->value, '\\') : null;
        }
        if (
            $expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && strtolower($expr->name->toString()) === 'class'
        ) {
            $resolved = $expr->class->getAttribute('resolvedName');
            $handler = ($resolved instanceof Node\Name ? $resolved : $expr->class)->toString();

            return strlen($handler) <= self::MAX_IDENTIFIER_BYTES ? $handler : null;
        }

        return null;
    }

    /** @return array{0:array<string,mixed>,1:?string} */
    private function metadata(string $handler): array
    {
        $candidates = array_values(array_filter(
            $this->symbols->find($handler),
            static fn(array $symbol): bool => $symbol['kind'] === 'class',
        ));
        if ($candidates === []) {
            $this->diagnostic('symbol_not_found', self::SOURCE, null, sprintf('Command handler %s was not found in project source.', $handler));
            $metadata = $this->emptyMetadata();
            $metadata['dynamic_fields'] = ['definition'];

            return [$metadata, null];
        }
        if (count($candidates) !== 1) {
            $this->diagnostic('ambiguous_symbol', self::SOURCE, null, sprintf('Command handler %s resolves to multiple project declarations.', $handler));
            $metadata = $this->emptyMetadata();
            $metadata['dynamic_fields'] = ['definition'];

            return [$metadata, null];
        }

        $path = $candidates[0]['path'];

        try {
            $nodes = $this->parse($this->paths->projectFile($path), $path);
        } catch (RuntimeException $error) {
            $this->diagnostic('command_handler_source_invalid', $path, null, $error->getMessage());
            $metadata = $this->emptyMetadata();
            $metadata['dynamic_fields'] = ['definition'];

            return [$metadata, $path];
        }
        if ($nodes === null) {
            $metadata = $this->emptyMetadata();
            $metadata['dynamic_fields'] = ['definition'];

            return [$metadata, $path];
        }

        $class = $this->classNode($nodes, $handler);
        if (!$class instanceof Node\Stmt\Class_) {
            $this->diagnostic('symbol_not_found', $path, null, sprintf('Command handler class %s could not be matched to its source declaration.', $handler));
            $metadata = $this->emptyMetadata();
            $metadata['dynamic_fields'] = ['definition'];

            return [$metadata, $path];
        }

        return [$this->definitions->scan($class), $path];
    }

    /** @return list<Node\Stmt>|null */
    private function parse(string $path, string $source): ?array
    {
        $this->secrets->assertAllowed($source);

        try {
            $nodes = $this->parser->parse($this->read($path));
        } catch (Error $error) {
            $this->diagnostic('parse_error', $source, $error->getStartLine() ?: null, $error->getRawMessage());

            return null;
        }
        if (!is_array($nodes)) {
            $this->diagnostic('parse_error', $source, null, 'PHP parser returned no syntax tree.');

            return null;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]));
        $traverser->addVisitor(new PhpNodeBudgetVisitor());

        try {
            /** @var list<Node\Stmt> $resolved */
            $resolved = $traverser->traverse($nodes);

            return $resolved;
        } catch (Error $error) {
            $this->diagnostic('parse_error', $source, $error->getStartLine() ?: null, $error->getRawMessage());

            return null;
        } catch (RuntimeException $error) {
            $this->diagnostic('inspection_limit_exceeded', $source, null, $error->getMessage());

            return null;
        }
    }

    private function read(string $path): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Command source could not be read safely.');
        }

        try {
            $source = stream_get_contents($handle, self::MAX_SOURCE_BYTES + 1);
        } finally {
            fclose($handle);
        }

        if (!is_string($source) || str_contains($source, "\0")) {
            throw new RuntimeException('Command source could not be read safely.');
        }
        if (strlen($source) > self::MAX_SOURCE_BYTES) {
            throw new RuntimeException('Command source exceeds the 1 MiB inspection limit.');
        }

        return $source;
    }

    /** @param list<Node\Stmt> $nodes */
    private function returnedArray(array $nodes): ?Node\Expr\Array_
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Return_ && $node->expr instanceof Node\Expr\Array_) {
                return $node->expr;
            }
        }

        return null;
    }

    private function routeName(?Node\Expr $expr): ?string
    {
        return $expr instanceof Node\Scalar\String_ && strlen($expr->value) <= self::MAX_ROUTE_NAME_BYTES ? $expr->value : null;
    }

    /** @param list<string> $values @return list<string> */
    private function unique(array $values): array
    {
        $values = array_values(array_unique(array_filter($values, static fn(string $value): bool => $value !== '')));
        sort($values, SORT_STRING);

        return $values;
    }
}
