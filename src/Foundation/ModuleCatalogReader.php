<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation;

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\Project;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type ModuleDefinition array{
 *     name:string,
 *     packages:array<string,string>,
 *     built_in:bool,
 *     description:string,
 *     aliases:list<string>,
 *     config:list<string>,
 *     schemas:list<string>
 * }
 * @phpstan-type ModuleIntelligence array{
 *     name:string,
 *     packages:array<string,array{constraint:string,installed:bool,state:string}>,
 *     built_in:bool,
 *     description:string,
 *     aliases:list<string>,
 *     config:list<string>,
 *     schemas:list<string>,
 *     config_present:list<string>,
 *     config_missing:list<string>,
 *     status:list<string>
 * }
 */
final class ModuleCatalogReader
{
    private const string CONFIG_PATTERN = '~^[A-Za-z0-9_.-]+\.php$~D';

    private const int MAX_ARRAY_ITEMS = 256;

    private const int MAX_DEPTH = 8;

    private const int MAX_MODULES = 128;

    private const int MAX_SOURCE_BYTES = 1_048_576;

    private const int MAX_STRING_BYTES = 8_192;

    private const string MODULE_PATTERN = '~^[a-z][a-z0-9_-]*$~D';

    private const string PACKAGE_PATTERN = '~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D';

    private readonly Parser $parser;

    /** @var array<string, ModuleDefinition>|null */
    private ?array $definitions = null;

    /** @var array<string, ModuleIntelligence>|null */
    private ?array $modules = null;

    public function __construct(
        private readonly Project $project,
        private readonly ComposerInspector $composer,
        ?Parser $parser = null,
    ) {
        $this->parser = $parser ?? new ParserFactory()->createForNewestSupportedVersion();
    }

    /** @return array<string, ModuleDefinition> */
    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $source = $this->readSource($this->sourcePath());

        try {
            $nodes = $this->parser->parse($source);
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to parse installed Foundation ModuleCatalog: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($nodes)) {
            throw new RuntimeException('Installed Foundation ModuleCatalog parser returned no syntax tree.');
        }

        $modules = $this->literal($this->moduleExpression($nodes));

        return $this->definitions = $this->normalizeDefinitions($modules);
    }

    /** @return array<string, ModuleIntelligence> */
    public function modules(): array
    {
        if ($this->modules !== null) {
            return $this->modules;
        }

        $modules = [];

        foreach ($this->definitions() as $name => $definition) {
            $packages = [];
            $allPackagesInstalled = true;

            foreach ($definition['packages'] as $package => $constraint) {
                $installed = $this->composer->package($package);
                $present = $installed?->installedVersion !== null;
                $allPackagesInstalled = $allPackagesInstalled && $present;
                $packages[$package] = [
                    'constraint' => $constraint,
                    'installed' => $present,
                    'state' => $installed?->state() ?? 'missing',
                ];
            }

            $presentConfig = [];
            $missingConfig = [];

            foreach ($definition['config'] as $config) {
                if (is_file($this->project->root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $config)) {
                    $presentConfig[] = $config;
                } else {
                    $missingConfig[] = $config;
                }
            }

            $status = ['cataloged'];

            if ($definition['built_in']) {
                $status[] = 'built_in';
            }

            if ($packages !== []) {
                $status[] = $allPackagesInstalled ? 'packages_installed' : 'packages_missing';
            }

            if ($presentConfig !== []) {
                $status[] = 'config_present';
            }

            if ($missingConfig !== []) {
                $status[] = 'config_missing';
            }

            $status[] = 'runtime_activation_unknown';
            $modules[$name] = [
                'name' => $name,
                'packages' => $packages,
                'built_in' => $definition['built_in'],
                'description' => $definition['description'],
                'aliases' => $definition['aliases'],
                'config' => $definition['config'],
                'schemas' => $definition['schemas'],
                'config_present' => $presentConfig,
                'config_missing' => $missingConfig,
                'status' => $status,
            ];
        }

        return $this->modules = $modules;
    }

    /** @return ModuleIntelligence|null */
    public function resolve(string $module): ?array
    {
        $normalized = strtolower(trim($module));

        if ($normalized === '') {
            return null;
        }

        foreach ($this->definitions() as $name => $definition) {
            if (
                $normalized === $name
                || isset($definition['packages'][$normalized])
                || in_array($normalized, $definition['aliases'], true)
            ) {
                return $this->modules()[$name];
            }
        }

        return null;
    }

    public function sourcePath(): string
    {
        $root = $this->composer->foundation()?->installPath;

        if ($root === null) {
            throw new RuntimeException('Installed Foundation package root is unavailable.');
        }

        $path = realpath($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Module' . DIRECTORY_SEPARATOR . 'ModuleCatalog.php');

        if ($path === false || !is_file($path)) {
            throw new RuntimeException('Installed Foundation ModuleCatalog.php is unavailable.');
        }

        if (!$this->inside($path, $root)) {
            throw new RuntimeException('Installed Foundation ModuleCatalog.php resolves outside the Foundation package root.');
        }

        return $path;
    }

    /** @param list<Node> $nodes */
    private function findModuleExpression(array $nodes): ?Expr
    {
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Namespace_) {
                $expression = $this->findModuleExpression($node->stmts);

                if ($expression !== null) {
                    return $expression;
                }

                continue;
            }

            if (!$node instanceof Stmt\Class_ || $node->name?->toString() !== 'ModuleCatalog') {
                continue;
            }

            foreach ($node->stmts as $statement) {
                if (!$statement instanceof Stmt\ClassConst) {
                    continue;
                }

                foreach ($statement->consts as $constant) {
                    if ($constant->name->toString() === 'MODULES') {
                        return $constant->value;
                    }
                }
            }
        }

        return null;
    }

    private function inside(string $path, string $root): bool
    {
        $root = rtrim($root, '\\/');
        $prefix = $root . DIRECTORY_SEPARATOR;

        if (DIRECTORY_SEPARATOR === '\\') {
            return strcasecmp($path, $root) === 0 || strncasecmp($path, $prefix, strlen($prefix)) === 0;
        }

        return $path === $root || str_starts_with($path, $prefix);
    }

    private function literal(Expr $expression, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new RuntimeException('Installed Foundation ModuleCatalog literal nesting limit exceeded.');
        }

        return match (true) {
            $expression instanceof Expr\Array_ => $this->literalArray($expression, $depth + 1),
            $expression instanceof Scalar\String_ => $this->string($expression->value),
            $expression instanceof Scalar\Int_ => $expression->value,
            $expression instanceof Scalar\Float_ => $expression->value,
            $expression instanceof Expr\ConstFetch => $this->literalConstant($expression),
            $expression instanceof Expr\UnaryMinus => -$this->numericLiteral($expression->expr, $depth + 1),
            $expression instanceof Expr\UnaryPlus => $this->numericLiteral($expression->expr, $depth + 1),
            default => throw new RuntimeException(sprintf(
                'Installed Foundation ModuleCatalog contains non-literal expression %s.',
                $expression::class,
            )),
        };
    }

    /** @return array<array-key, mixed> */
    private function literalArray(Expr\Array_ $array, int $depth): array
    {
        if (count($array->items) > self::MAX_ARRAY_ITEMS) {
            throw new RuntimeException('Installed Foundation ModuleCatalog array item limit exceeded.');
        }

        $result = [];

        foreach ($array->items as $item) {
            if ($item === null || $item->unpack) {
                throw new RuntimeException('Installed Foundation ModuleCatalog contains unsupported array syntax.');
            }

            $value = $this->literal($item->value, $depth);

            if ($item->key === null) {
                $result[] = $value;

                continue;
            }

            $key = $this->literal($item->key, $depth);

            if (!is_int($key) && !is_string($key)) {
                throw new RuntimeException('Installed Foundation ModuleCatalog contains a non-scalar array key.');
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function literalConstant(Expr\ConstFetch $constant): ?bool
    {
        return match (strtolower($constant->name->toString())) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => throw new RuntimeException(sprintf(
                'Installed Foundation ModuleCatalog contains non-literal constant %s.',
                $constant->name->toString(),
            )),
        };
    }

    /** @param list<Node> $nodes */
    private function moduleExpression(array $nodes): Expr
    {
        $expression = $this->findModuleExpression($nodes);

        if ($expression !== null) {
            return $expression;
        }

        throw new RuntimeException('Installed Foundation ModuleCatalog::MODULES constant was not found.');
    }

    /** @return array<string, ModuleDefinition> */
    private function normalizeDefinitions(mixed $modules): array
    {
        if (!is_array($modules) || count($modules) > self::MAX_MODULES) {
            throw new RuntimeException('Installed Foundation ModuleCatalog::MODULES must be a bounded literal array.');
        }

        $normalized = [];
        $resolutions = [];

        foreach ($modules as $name => $definition) {
            if (!is_string($name) || preg_match(self::MODULE_PATTERN, $name) !== 1 || !is_array($definition)) {
                throw new RuntimeException('Installed Foundation ModuleCatalog contains an invalid module definition.');
            }

            $packages = $this->stringMap($definition['packages'] ?? null, 'packages', self::PACKAGE_PATTERN);
            $description = $definition['description'] ?? null;
            $builtIn = $definition['built_in'] ?? false;

            if (!is_string($description) || trim($description) === '' || strlen($description) > self::MAX_STRING_BYTES || !is_bool($builtIn)) {
                throw new RuntimeException(sprintf('Installed Foundation module "%s" has invalid metadata.', $name));
            }

            $aliases = $this->stringList($definition['aliases'] ?? null, 'aliases', lowercase: true);
            $config = $this->stringList($definition['config'] ?? null, 'config', self::CONFIG_PATTERN);
            $schemas = $this->stringList($definition['schemas'] ?? null, 'schemas');

            $normalized[$name] = [
                'name' => $name,
                'packages' => $packages,
                'built_in' => $builtIn,
                'description' => $description,
                'aliases' => $aliases,
                'config' => $config,
                'schemas' => $schemas,
            ];

            foreach ([$name, ...array_keys($packages), ...$aliases] as $resolution) {
                $resolution = strtolower($resolution);

                if (isset($resolutions[$resolution]) && $resolutions[$resolution] !== $name) {
                    throw new RuntimeException(sprintf(
                        'Installed Foundation ModuleCatalog has ambiguous module key "%s".',
                        $resolution,
                    ));
                }

                $resolutions[$resolution] = $name;
            }
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function numericLiteral(Expr $expression, int $depth): int|float
    {
        $value = $this->literal($expression, $depth);

        if (!is_int($value) && !is_float($value)) {
            throw new RuntimeException('Installed Foundation ModuleCatalog contains a non-numeric unary expression.');
        }

        return $value;
    }

    private function readSource(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to read installed Foundation ModuleCatalog.php.');
        }

        try {
            $source = stream_get_contents($handle, self::MAX_SOURCE_BYTES + 1);
        } finally {
            fclose($handle);
        }

        if (!is_string($source)) {
            throw new RuntimeException('Unable to read installed Foundation ModuleCatalog.php.');
        }

        if (strlen($source) > self::MAX_SOURCE_BYTES) {
            throw new RuntimeException('Installed Foundation ModuleCatalog.php exceeds the 1 MiB safety limit.');
        }
        if (str_contains($source, "\0")) {
            throw new RuntimeException('Installed Foundation ModuleCatalog.php is binary.');
        }

        return $source;
    }

    private function string(string $value): string
    {
        if (strlen($value) > self::MAX_STRING_BYTES) {
            throw new RuntimeException('Installed Foundation ModuleCatalog literal string limit exceeded.');
        }

        return $value;
    }

    /** @return list<string> */
    private function stringList(
        mixed $value,
        string $field,
        ?string $pattern = null,
        bool $lowercase = false,
    ): array {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_ARRAY_ITEMS) {
            throw new RuntimeException(sprintf('Installed Foundation ModuleCatalog field "%s" must be a bounded list.', $field));
        }

        $result = [];

        foreach ($value as $item) {
            if (
                !is_string($item)
                || trim($item) === ''
                || strlen($item) > self::MAX_STRING_BYTES
                || ($pattern !== null && preg_match($pattern, $item) !== 1)
            ) {
                throw new RuntimeException(sprintf('Installed Foundation ModuleCatalog field "%s" is invalid.', $field));
            }

            $result[] = $lowercase ? strtolower($item) : $item;
        }

        return array_values(array_unique($result));
    }

    /** @return array<string, string> */
    private function stringMap(mixed $value, string $field, string $keyPattern): array
    {
        if (!is_array($value) || count($value) > self::MAX_ARRAY_ITEMS) {
            throw new RuntimeException(sprintf('Installed Foundation ModuleCatalog field "%s" must be a bounded array.', $field));
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (
                !is_string($key)
                || preg_match($keyPattern, $key) !== 1
                || !is_string($item)
                || trim($item) === ''
                || strlen($item) > self::MAX_STRING_BYTES
            ) {
                throw new RuntimeException(sprintf('Installed Foundation ModuleCatalog field "%s" is invalid.', $field));
            }

            $result[strtolower($key)] = $item;
        }

        ksort($result, SORT_STRING);

        return $result;
    }
}
