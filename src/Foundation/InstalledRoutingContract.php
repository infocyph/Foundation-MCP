<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation;

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Security\PathPolicy;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;

final class InstalledRoutingContract
{
    private const int MAX_SOURCE_BYTES = 1_048_576;

    private readonly Parser $parser;
    /** @var list<string>|null */
    private ?array $routeFiles = null;
    /** @var array<string,string>|null */
    private ?array $methods = null;
    /** @var array<string,list<array{method:string,suffix:string,action:string,key:string,nameable:bool}>> */
    private array $resourceSpecs = [];

    public function __construct(
        private readonly Project $project,
        private readonly ComposerInspector $composer,
        ?Parser $parser = null,
    ) {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
    }

    /** @return list<string> */
    public function routeFiles(): array
    {
        if ($this->routeFiles !== null) {
            return $this->routeFiles;
        }

        $nodes = $this->parsePackageFile('infocyph/foundation', 'src/Routing/RouteFileLoader.php');
        $default = $this->constructorParameterDefault($nodes, 'RouteFileLoader', 'files');
        if (!$default instanceof Node\Expr) {
            throw new RuntimeException('Installed Foundation route-file contract could not be read statically.');
        }

        $value = $this->literal($default, []);
        if (!is_array($value)) {
            throw new RuntimeException('Installed Foundation route-file contract is invalid.');
        }

        return $this->routeFiles = $this->normalizeRouteFiles($value);
    }

    /** @return array<string, string> enum case => HTTP method */
    public function httpMethods(): array
    {
        if ($this->methods !== null) {
            return $this->methods;
        }

        $nodes = $this->parsePackageFile('infocyph/webrick', 'src/Constants/HttpMethodEnum.php');
        $enum = $this->namedEnum($nodes, 'HttpMethodEnum');
        if (!$enum instanceof Node\Stmt\Enum_) {
            throw new RuntimeException('Installed Webrick HTTP-method contract could not be read statically.');
        }

        $methods = [];
        foreach ($enum->stmts as $statement) {
            if ($statement instanceof Node\Stmt\EnumCase && $statement->expr instanceof Node\Scalar\String_) {
                $methods[$statement->name->toString()] = $this->normalizeHttpMethod($statement->expr->value);
            }
        }

        if ($methods === []) {
            throw new RuntimeException('Installed Webrick HTTP-method contract could not be read statically.');
        }

        return $this->methods = $methods;
    }

    /**
     * @return list<array{method:string,suffix:string,action:string,key:string,nameable:bool}>
     */
    public function resourceSpec(string $param = 'id', string $patchAction = 'update'): array
    {
        if ($param === '' || $patchAction === '') {
            throw new RuntimeException('Resource route parameters must be non-empty.');
        }

        $cacheKey = $param."\0".$patchAction;
        if (isset($this->resourceSpecs[$cacheKey])) {
            return $this->resourceSpecs[$cacheKey];
        }

        $nodes = $this->parsePackageFile('infocyph/webrick', 'src/Router/Definition/Registrar.php');
        $return = $this->methodReturnExpression($nodes, 'Registrar', 'buildResourceSpec');
        if (!$return instanceof Node\Expr) {
            throw new RuntimeException('Installed Webrick resource-route contract could not be read statically.');
        }

        $value = $this->literal($return, [
            'param' => $param,
            'patchAction' => $patchAction,
            '__http_methods' => $this->httpMethods(),
        ]);

        return $this->resourceSpecs[$cacheKey] = $this->normalizeResourceSpec($value);
    }

    /**
     * @param list<Node\Stmt> $nodes
     */
    private function constructorParameterDefault(array $nodes, string $className, string $parameter): ?Node\Expr
    {
        $class = $this->namedClass($nodes, $className);
        $method = $class?->getMethod('__construct');
        if (!$method instanceof Node\Stmt\ClassMethod) {
            return null;
        }

        foreach ($method->params as $param) {
            if ($param->var instanceof Node\Expr\Variable && $param->var->name === $parameter) {
                return $param->default;
            }
        }

        return null;
    }

    /** @param list<Node\Stmt> $nodes */
    private function methodReturnExpression(array $nodes, string $className, string $methodName): ?Node\Expr
    {
        $method = $this->namedClass($nodes, $className)?->getMethod($methodName);
        foreach ($method?->stmts ?? [] as $statement) {
            if ($statement instanceof Node\Stmt\Return_ && $statement->expr instanceof Node\Expr) {
                return $statement->expr;
            }
        }

        return null;
    }

    /** @param list<Node\Stmt> $nodes */
    private function namedClass(array $nodes, string $name): ?Node\Stmt\Class_
    {
        foreach ($this->statements($nodes) as $node) {
            if ($node instanceof Node\Stmt\Class_ && $node->name?->toString() === $name) {
                return $node;
            }
        }

        return null;
    }

    /** @param list<Node\Stmt> $nodes */
    private function namedEnum(array $nodes, string $name): ?Node\Stmt\Enum_
    {
        foreach ($this->statements($nodes) as $node) {
            if ($node instanceof Node\Stmt\Enum_ && $node->name?->toString() === $name) {
                return $node;
            }
        }

        return null;
    }

    /** @param array<array-key,mixed> $value @return list<string> */
    private function normalizeRouteFiles(array $value): array
    {
        $files = [];
        foreach ($value as $file) {
            if (!is_string($file) || preg_match('~^[A-Za-z0-9_.-]+\.php$~D', $file) !== 1) {
                throw new RuntimeException('Installed Foundation route-file contract is invalid.');
            }
            $files[] = $file;
        }

        $files = array_values(array_unique($files));
        if ($files === [] || count($files) > 32) {
            throw new RuntimeException('Installed Foundation route-file contract is invalid.');
        }

        return $files;
    }

    private function normalizeHttpMethod(string $value): string
    {
        $value = strtoupper($value);
        if ($value === '' || preg_match('~^[A-Z]+$~D', $value) !== 1) {
            throw new RuntimeException('Installed Webrick HTTP-method contract is invalid.');
        }

        return $value;
    }

    /**
     * @param list<Node\Stmt> $nodes
     * @return list<Node\Stmt>
     */
    private function statements(array $nodes): array
    {
        $statements = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                array_push($statements, ...$this->statements($node->stmts));
                continue;
            }
            $statements[] = $node;
        }
        return $statements;
    }

    /** @return list<Node\Stmt> */
    private function parsePackageFile(string $package, string $relative): array
    {
        $installed = $this->composer->package($package);
        if ($installed?->installPath === null) {
            throw new RuntimeException(sprintf('Required routing package %s is not installed.', $package));
        }

        $paths = new PathPolicy($this->project->root, [$package => $installed->installPath]);
        $path = $paths->packageFile($package, $relative);
        $source = $this->read($path);

        try {
            $nodes = $this->parser->parse($source);
        } catch (Error $error) {
            throw new RuntimeException('Installed routing contract contains invalid PHP: '.$error->getRawMessage(), 0, $error);
        }

        if (!is_array($nodes)) {
            throw new RuntimeException('Installed routing contract parser returned no syntax tree.');
        }

        return $nodes;
    }

    private function read(string $path): string
    {
        $size = filesize($path);
        if ($size !== false && $size > self::MAX_SOURCE_BYTES) {
            throw new RuntimeException('Installed routing contract exceeds the 1 MiB read limit.');
        }

        $source = file_get_contents($path);
        if ($source === false || strlen($source) > self::MAX_SOURCE_BYTES || str_contains($source, "\0")) {
            throw new RuntimeException('Installed routing contract could not be read safely.');
        }

        return $source;
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function literal(Node\Expr $expr, array $variables): mixed
    {
        return match (true) {
            $expr instanceof Node\Scalar\String_ => $expr->value,
            $expr instanceof Node\Scalar\Int_ => $expr->value,
            $expr instanceof Node\Scalar\Float_ => $expr->value,
            $expr instanceof Node\Expr\Array_ => $this->literalArray($expr, $variables),
            $expr instanceof Node\Expr\ConstFetch => $this->literalConst($expr),
            $expr instanceof Node\Expr\Variable && is_string($expr->name) => $variables[$expr->name] ?? throw new RuntimeException('Installed routing contract contains an unsupported variable expression.'),
            $expr instanceof Node\Expr\BinaryOp\Concat => (string) $this->literal($expr->left, $variables).(string) $this->literal($expr->right, $variables),
            $expr instanceof Node\Expr\BinaryOp\NotIdentical => $this->literal($expr->left, $variables) !== $this->literal($expr->right, $variables),
            $expr instanceof Node\Expr\PropertyFetch => $this->enumValue($expr, $variables),
            default => throw new RuntimeException('Installed routing contract contains a non-literal expression.'),
        };
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<array-key, mixed>
     */
    private function literalArray(Node\Expr\Array_ $array, array $variables): array
    {
        $result = [];

        foreach ($array->items as $item) {
            if (!$item instanceof Node\Expr\ArrayItem || $item->unpack) {
                throw new RuntimeException('Installed routing contract contains unsupported array syntax.');
            }

            $value = $this->literal($item->value, $variables);
            if ($item->key === null) {
                $result[] = $value;
                continue;
            }

            $key = $this->literal($item->key, $variables);
            if (!is_int($key) && !is_string($key)) {
                throw new RuntimeException('Installed routing contract contains an invalid array key.');
            }
            $result[$key] = $value;
        }

        return $result;
    }

    private function literalConst(Node\Expr\ConstFetch $expr): bool|null
    {
        return match (strtolower($expr->name->toString())) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => throw new RuntimeException('Installed routing contract contains an unsupported constant expression.'),
        };
    }

    /** @param array<string, mixed> $variables */
    private function enumValue(Node\Expr\PropertyFetch $expr, array $variables): string
    {
        if (
            !$expr->name instanceof Node\Identifier
            || $expr->name->toString() !== 'value'
            || !$expr->var instanceof Node\Expr\ClassConstFetch
            || !$expr->var->name instanceof Node\Identifier
        ) {
            throw new RuntimeException('Installed routing contract contains an unsupported property expression.');
        }

        $methods = $variables['__http_methods'] ?? null;
        $case = $expr->var->name->toString();
        if (!is_array($methods) || !isset($methods[$case]) || !is_string($methods[$case])) {
            throw new RuntimeException('Installed routing contract references an unknown HTTP method.');
        }

        return $methods[$case];
    }

    /**
     * @return list<array{method:string,suffix:string,action:string,key:string,nameable:bool}>
     */
    private function normalizeResourceSpec(mixed $value): array
    {
        if (!is_array($value) || count($value) > 32) {
            throw new RuntimeException('Installed Webrick resource-route contract is invalid.');
        }

        $spec = [];
        foreach ($value as $row) {
            if (
                !is_array($row)
                || count($row) !== 5
                || !is_string($row[0] ?? null)
                || !is_string($row[1] ?? null)
                || !is_string($row[2] ?? null)
                || !is_string($row[3] ?? null)
                || !is_bool($row[4] ?? null)
            ) {
                throw new RuntimeException('Installed Webrick resource-route contract is invalid.');
            }

            $spec[] = [
                'method' => strtoupper($row[0]),
                'suffix' => $row[1],
                'action' => $row[2],
                'key' => $row[3],
                'nameable' => $row[4],
            ];
        }

        return $spec;
    }
}
