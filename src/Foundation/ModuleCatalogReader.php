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
    private const int MAX_SOURCE_BYTES = 1_048_576;
    private const string MODULE_PATTERN = '~^[a-z][a-z0-9_-]*$~D';
    private const string PACKAGE_PATTERN = '~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D';
    private const string CONFIG_PATTERN = '~^[A-Za-z0-9_.-]+\.php$~D';

    /** @var array<string, ModuleDefinition>|null */
    private ?array $definitions = null;

    /** @var array<string, ModuleIntelligence>|null */
    private ?array $modules = null;

    private readonly Parser $parser;

    public function __construct(
        private readonly Project $project,
        private readonly ComposerInspector $composer,
        ?Parser $parser = null,
    ) {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
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
            throw new RuntimeException('Unable to parse installed Foundation ModuleCatalog: '.$exception->getMessage(), 0, $exception);
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
                if (is_file($this->project->root.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.$config)) {
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

        $path = realpath($root.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Module'.DIRECTORY_SEPARATOR.'ModuleCatalog.php');

        if ($path === false || !is_file($path)) {
            throw new RuntimeException('Installed Foundation ModuleCatalog.php is unavailable.');
        }

        if (!$this->inside($path, $root)) {
            throw new RuntimeException('Installed Foundation ModuleCatalog.php resolves outside the Foundation package root.');
        }

        return $path;
    }

    private function readSource(string $path): string
    {
        $size = filesize($path);

        if ($size !== false && $size > self::MAX_SOURCE_BYTES) {
            throw new RuntimeException('Installed Foundation ModuleCatalog.php exceeds the 1 MiB safety limit.');
        }

        $source = file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException('Unable to read installed Foundation ModuleCatalog.php.');
        }

        if (strlen($source) > self::MAX_SOURCE_BYTES) {
            throw new RuntimeException('Installed Foundation ModuleCatalog.php exceeds the 1 MiB safety limit.');
        }

        return $source;
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

    private function literal(Expr $expression): mixed
    {
        return match (true) {
            $expression instanceof Expr\Array_ => $this->literalArray($expression),
            $expression instanceof Scalar\String_ => $expression->value,
            $expression instanceof Scalar\Int_ => $expression->value,
            $expression instanceof Scalar\Float_ => $expression->value,
            $expression instanceof Expr\ConstFetch => $this->literalConstant($expression),
            $expression instanceof Expr\UnaryMinus => -$this->numericLiteral($expression->expr),
            $expression instanceof Expr\UnaryPlus => $this->numericLiteral($expression->expr),
            default => throw new RuntimeException(sprintf(
                'Installed Foundation ModuleCatalog contains non-literal expression %s.',
                $expression::class,
            )),
        };
    }

    /** @return array<array-key, mixed> */
    private function literalArray(Expr\Array_ $array): array
    {
        $result = [];

        foreach ($array->items as $item) {
            if ($item === null || $item->unpack) {
                throw new RuntimeException('Installed Foundation ModuleCatalog contains unsupported array syntax.');
            }

            $value = $this->literal($item->value);

            if ($item->key === null) {
                $result[] = $value;

                continue;
            }

            $key = $this->literal($item->key);

            if (!is_int($key) && !is_string($key)) {
                throw new RuntimeException('Installed Foundation ModuleCatalog contains a non-scalar array key.');
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function literalConstant(Expr\ConstFetch $constant): bool|null
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

    private function numericLiteral(Expr $expression): int|float
    {
        $value = $this->literal($expression);

        if (!is_int($value) && !is_float($value)) {
            throw new RuntimeException('Installed Foundation ModuleCatalog contains a non-numeric unary expression.');
        }

        return $value;
    }

    /** @return array<string, ModuleDefinition> */
    private function normalizeDefinitions(mixed $modules): array
    {
        if (!is_array($modules)) {
            throw new RuntimeException('Installed Foundation ModuleCatalog::MODULES must be a literal array.');
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

            if (!is_string($description) || trim($description) === '' || !is_bool($builtIn)) {
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

        return $normalized;
    }

    /** @return array<string, string> */
    private function stringMap(mixed $value, string $field, string $keyPattern): array
    {
        if (!is_array($value)) {
            throw new RuntimeException(sprintf('Installed Foundation ModuleCatalog field "%s" must be an array.', $field));
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (
                !is_string($key)
                || preg_match($keyPattern, $key) !== 1
                || !is_string($item)
                || trim($item) === ''
            ) {
                throw new RuntimeException(sprintf('Installed Foundation ModuleCatalog field "%s" is invalid.', $field));
            }

            $result[strtolower($key)] = $item;
        }

        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return list<string> */
    private function stringList(
        mixed $value,
        string $field,
        ?string $pattern = null,
        bool $lowercase = false,
    ): array {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException(sprintf('Installed Foundation ModuleCatalog field "%s" must be a list.', $field));
        }

        $result = [];

        foreach ($value as $item) {
            if (
                !is_string($item)
                || trim($item) === ''
                || ($pattern !== null && preg_match($pattern, $item) !== 1)
            ) {
                throw new RuntimeException(sprintf('Installed Foundation ModuleCatalog field "%s" is invalid.', $field));
            }

            $result[] = $lowercase ? strtolower($item) : $item;
        }

        return array_values(array_unique($result));
    }

    private function inside(string $path, string $root): bool
    {
        $root = rtrim($root, "\\/");
        $prefix = $root.DIRECTORY_SEPARATOR;

        if (DIRECTORY_SEPARATOR === '\\') {
            return strcasecmp($path, $root) === 0 || strncasecmp($path, $prefix, strlen($prefix)) === 0;
        }

        return $path === $root || strncmp($path, $prefix, strlen($prefix)) === 0;
    }
}
