<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation\Internal;

use Infocyph\FoundationMcp\Analysis\Internal\PhpNodeBudgetVisitor;
use Infocyph\FoundationMcp\Security\Redactor;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use RuntimeException;

/**
 * @phpstan-type EnvironmentRef array{name:string,helper:string,has_default:bool,default:mixed}
 * @phpstan-type ConfigEntry array{key:string,layer:string,value:mixed,status:string,environment:list<EnvironmentRef>,classes:list<string>,owner:string,source:string,line:int,effective:bool}
 * @phpstan-type Diagnostic array{code:string,source:?string,line:?int,message:string}
 */
final class ConfigEntryExtractor
{
    private const int MAX_CLASSES_PER_ENTRY = 32;

    private const int MAX_DEPTH = 64;

    private const int MAX_DIAGNOSTICS = 100;

    private const int MAX_ENTRIES = 3_000;

    private const int MAX_SOURCE_BYTES = 1_048_576;

    private const string SECRET_KEY_PATTERN = '~(?:^|[._-])(?:password|secret|token|api[_-]?key|private[_-]?key|authorization|cookie|credential|dsn)(?:[._-]|$)~i';

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    public function __construct(
        private readonly Parser $parser,
        private readonly Redactor $redactor,
    ) {}

    /**
     * @param list<Node\Stmt> $statements
     * @return list<ConfigEntry>
     */
    public function array(
        Node\Expr\Array_ $array,
        string $prefix,
        string $layer,
        string $owner,
        string $source,
        array $statements = [],
        bool $normalizeBootstrap = false,
    ): array {
        $evaluator = new StaticConfigEvaluator();
        $entries = [];

        try {
            $evaluator->learn($statements);
            $this->flatten($array, $prefix, $layer, $owner, $source, $evaluator, $entries, $normalizeBootstrap, 0);
        } catch (RuntimeException $error) {
            $this->diagnostic('inspection_limit_exceeded', $source, $array->getStartLine(), $error->getMessage());

            return [];
        }

        return $entries;
    }

    /** @return list<Node\Stmt\Class_> */
    public function classes(array $nodes): array
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

    /** @return list<Diagnostic> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /** @return list<ConfigEntry> */
    public function file(string $path, string $source, string $namespace, string $layer, string $owner): array
    {
        $nodes = $this->parse($path, $source);

        if ($nodes === null) {
            return [];
        }

        $return = $this->topLevelReturn($nodes);

        if (!$return?->expr instanceof Node\Expr\Array_) {
            $this->diagnostic('config_dynamic', $source, $return?->getStartLine(), 'Config file return value is not a statically inspectable array.');

            return [];
        }

        return $this->array(
            $return->expr,
            $namespace,
            $layer,
            $owner,
            $source,
            $this->statementsBefore($nodes, $return),
        );
    }

    /** @return list<ConfigEntry> */
    public function method(string $path, string $source, string $className, string $methodName, string $layer, string $owner): array
    {
        $nodes = $this->parse($path, $source);

        if ($nodes === null) {
            return [];
        }

        foreach ($this->classes($nodes) as $class) {
            $name = $class->namespacedName?->toString() ?? $class->name?->toString();

            if ($name !== $className) {
                continue;
            }

            foreach ($class->getMethods() as $method) {
                if ($method->name->toString() !== $methodName) {
                    continue;
                }

                $return = $this->methodReturn($method);

                if (!$return?->expr instanceof Node\Expr\Array_) {
                    $this->diagnostic('config_dynamic', $source, $return?->getStartLine(), sprintf('%s::%s() is not a statically inspectable array.', $className, $methodName));

                    return [];
                }

                return $this->array($return->expr, '', $layer, $owner, $source, $method->stmts ?? []);
            }
        }

        return [];
    }

    /** @return list<Node\Stmt>|null */
    public function parse(string $path, string $source): ?array
    {
        $contents = $this->readSource($path);

        if ($contents === null) {
            $this->diagnostic('source_unreadable', $source, null, sprintf('Config source is unreadable, binary, invalid UTF-8, or exceeds %d bytes.', self::MAX_SOURCE_BYTES));

            return null;
        }

        try {
            $nodes = $this->parser->parse($contents) ?? [];
        } catch (Error $error) {
            $this->diagnostic('parse_error', $source, $error->getStartLine(), $error->getRawMessage());

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
            $this->diagnostic('parse_error', $source, $error->getStartLine(), $error->getRawMessage());

            return null;
        } catch (RuntimeException $error) {
            $this->diagnostic('inspection_limit_exceeded', $source, null, $error->getMessage());

            return null;
        }
    }

    /** @param list<Node\Stmt> $nodes @return list<Node\Stmt> */
    public function statementsBefore(array $nodes, Node\Stmt\Return_ $return): array
    {
        $result = [];

        foreach ($nodes as $node) {
            if ($node === $return) {
                break;
            }

            $result[] = $node;
        }

        return $result;
    }

    /** @param list<Node\Stmt> $nodes */
    public function topLevelReturn(array $nodes): ?Node\Stmt\Return_
    {
        foreach ($nodes as $node) {
            $statements = $node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node];

            foreach ($statements as $statement) {
                if ($statement instanceof Node\Stmt\Return_) {
                    return $statement;
                }
            }
        }

        return null;
    }

    /** @param list<string> $classes @return list<string> */
    private function classesForEntry(array $classes, string $source, int $line): array
    {
        if (count($classes) > self::MAX_CLASSES_PER_ENTRY) {
            $this->diagnostic(
                'class_references_truncated',
                $source,
                $line,
                sprintf('Config entry class references are limited to %d values.', self::MAX_CLASSES_PER_ENTRY),
            );
        }

        return array_slice($classes, 0, self::MAX_CLASSES_PER_ENTRY);
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
            'message' => sprintf('Config extraction diagnostics are limited to %d entries.', self::MAX_DIAGNOSTICS),
        ];
    }

    /** @param list<EnvironmentRef> $environment @param list<string> $classes @return ConfigEntry */
    private function entry(
        string $key,
        string $layer,
        mixed $value,
        string $status,
        array $environment,
        array $classes,
        string $owner,
        string $source,
        int $line,
    ): array {
        return [
            'key' => $key,
            'layer' => $layer,
            'value' => $value,
            'status' => $status,
            'environment' => $environment,
            'classes' => $classes,
            'owner' => $owner,
            'source' => $source,
            'line' => $line,
            'effective' => false,
        ];
    }

    /** @param list<ConfigEntry> $entries */
    private function flatten(
        Node\Expr\Array_ $array,
        string $prefix,
        string $layer,
        string $owner,
        string $source,
        StaticConfigEvaluator $evaluator,
        array &$entries,
        bool $normalizeBootstrap,
        int $depth,
    ): void {
        if ($depth >= self::MAX_DEPTH) {
            throw new RuntimeException('Static config array exceeds the 64-level nesting limit.');
        }

        $nextIndex = 0;

        foreach ($array->items as $item) {
            if (count($entries) >= self::MAX_ENTRIES) {
                throw new RuntimeException(sprintf('Config source expansion exceeds the %d-entry limit.', self::MAX_ENTRIES));
            }

            if (!$item instanceof Node\Expr\ArrayItem || $item->unpack) {
                $this->diagnostic('config_dynamic', $source, $array->getStartLine(), 'Config array contains dynamic or unpacked syntax.');

                continue;
            }

            $key = $this->key($item, $nextIndex);

            if ($key === null) {
                $this->diagnostic('config_dynamic', $source, $item->getStartLine(), 'Config array key is dynamic.');

                continue;
            }

            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if ($normalizeBootstrap && $prefix === '' && in_array((string) $key, ['base_path', 'env', 'debug'], true)) {
                $path = 'app.' . $key;
            }

            if ($normalizeBootstrap && str_starts_with($path, '_')) {
                continue;
            }

            if ($item->value instanceof Node\Expr\Array_) {
                if ($item->value->items === []) {
                    $entries[] = $this->entry($path, $layer, [], 'literal', [], [], $owner, $source, $item->getStartLine());
                } else {
                    $this->flatten($item->value, $path, $layer, $owner, $source, $evaluator, $entries, $normalizeBootstrap, $depth + 1);
                }

                continue;
            }

            $result = $evaluator->evaluate($item->value);
            $entries[] = $this->entry(
                $path,
                $layer,
                $result['status'] === 'literal' ? $this->sanitize($path, $result['value']) : null,
                $result['status'],
                $this->sanitizeEnvironment($path, $result['environment']),
                $this->classesForEntry($result['classes'], $source, $item->getStartLine()),
                $owner,
                $source,
                $item->getStartLine(),
            );
        }
    }

    private function key(Node\Expr\ArrayItem $item, int &$nextIndex): int|string|null
    {
        if ($item->key === null) {
            return $nextIndex++;
        }

        if ($item->key instanceof Node\Scalar\String_) {
            return $item->key->value;
        }

        if ($item->key instanceof Node\Scalar\Int_) {
            $nextIndex = max($nextIndex, $item->key->value + 1);

            return $item->key->value;
        }

        return null;
    }

    private function methodReturn(Node\Stmt\ClassMethod $method): ?Node\Stmt\Return_
    {
        foreach ($method->stmts ?? [] as $statement) {
            if ($statement instanceof Node\Stmt\Return_) {
                return $statement;
            }
        }

        return null;
    }

    private function readSource(string $path): ?string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $contents = stream_get_contents($handle, self::MAX_SOURCE_BYTES + 1);
        } finally {
            fclose($handle);
        }

        if (
            !is_string($contents)
            || strlen($contents) > self::MAX_SOURCE_BYTES
            || str_contains($contents, "\0")
            || preg_match('//u', $contents) !== 1
        ) {
            return null;
        }

        return $contents;
    }

    private function sanitize(string $key, mixed $value): mixed
    {
        if (preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
            return '[REDACTED]';
        }

        return is_string($value) ? $this->redactor->redact($value) : $value;
    }

    /** @param list<EnvironmentRef> $environment @return list<EnvironmentRef> */
    private function sanitizeEnvironment(string $key, array $environment): array
    {
        foreach ($environment as &$reference) {
            if ($reference['has_default']) {
                $reference['default'] = $this->sanitize($key . '.' . $reference['name'], $reference['default']);
            }
        }
        unset($reference);

        return $environment;
    }
}
