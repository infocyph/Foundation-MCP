<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation\Internal;

use Infocyph\FoundationMcp\Security\Redactor;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;

/**
 * @phpstan-type ConfigEntry array{key:string,layer:string,value:mixed,status:string,environment:list<array{name:string,helper:string,has_default:bool,default:mixed}>,classes:list<string>,owner:string,source:string,line:int,effective:bool}
 * @phpstan-type Diagnostic array{code:string,source:?string,line:?int,message:string}
 */
final class ConfigEntryExtractor
{
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
        $evaluator->learn($statements);
        $entries = [];
        $this->flatten($array, $prefix, $layer, $owner, $source, $evaluator, $entries, $normalizeBootstrap);

        return $entries;
    }

    /** @param list<Node\Stmt> $nodes @return list<Node\Stmt\Class_> */
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
        $size = filesize($path);
        if ($size === false || $size > self::MAX_SOURCE_BYTES) {
            $this->diagnostic('source_too_large', $source, null, sprintf('Config source exceeds %d bytes.', self::MAX_SOURCE_BYTES));

            return null;
        }
        $contents = file_get_contents($path);
        if (!is_string($contents) || str_contains($contents, "\0")) {
            $this->diagnostic('source_unreadable', $source, null, 'Config source is unreadable or binary.');

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

    private function diagnostic(string $code, ?string $source, ?int $line, string $message): void
    {
        if (count($this->diagnostics) < self::MAX_DIAGNOSTICS) {
            $this->diagnostics[] = compact('code', 'source', 'line', 'message');
        }
    }

    /** @return ConfigEntry */
    private function entry(string $key, string $layer, mixed $value, string $status, array $environment, array $classes, string $owner, string $source, int $line): array
    {
        return compact('key', 'layer', 'value', 'status', 'environment', 'classes', 'owner', 'source', 'line') + ['effective' => false];
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
    ): void {
        $nextIndex = 0;
        foreach ($array->items as $item) {
            if (count($entries) >= self::MAX_ENTRIES) {
                $this->diagnostic('output_limit_exceeded', $source, $array->getStartLine(), sprintf('Config source expansion is limited to %d entries.', self::MAX_ENTRIES));

                return;
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
                    $this->flatten($item->value, $path, $layer, $owner, $source, $evaluator, $entries, $normalizeBootstrap);
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
                array_slice($result['classes'], 0, 32),
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

    private function sanitize(string $key, mixed $value): mixed
    {
        if (preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
            return '[REDACTED]';
        }

        return is_string($value) ? $this->redactor->redact($value) : $value;
    }

    /** @param list<array{name:string,helper:string,has_default:bool,default:mixed}> $environment */
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
