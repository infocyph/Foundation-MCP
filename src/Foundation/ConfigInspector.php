<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation;

use Infocyph\FoundationMcp\Analysis\SymbolIndex;
use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Foundation\Internal\StaticConfigEvaluator;
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
 * Static Foundation configuration precedence inspector.
 *
 * @phpstan-type EnvironmentRef array{name:string,helper:string,has_default:bool,default:mixed}
 * @phpstan-type ConfigEntry array{
 *   key:string,layer:string,value:mixed,status:string,environment:list<EnvironmentRef>,classes:list<string>,
 *   owner:string,source:string,line:int,effective:bool
 * }
 * @phpstan-type Diagnostic array{code:string,source:?string,line:?int,message:string}
 */
final class ConfigInspector
{
    private const int MAX_SOURCE_BYTES = 1_048_576;
    private const int MAX_FILES = 256;
    private const int MAX_ENTRIES = 3_000;
    private const int MAX_DIAGNOSTICS = 100;
    private const string SECRET_KEY_PATTERN = '~(?:^|[._-])(?:password|secret|token|api[_-]?key|private[_-]?key|authorization|cookie|credential|dsn)(?:[._-]|$)~i';

    private readonly Parser $parser;
    private readonly PathPolicy $paths;
    private readonly SecretPolicy $secrets;
    private readonly Redactor $redactor;
    private readonly SymbolIndex $symbols;

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    public function __construct(
        private readonly Project $project,
        private readonly ComposerInspector $composer,
        ?Parser $parser = null,
        ?SymbolIndex $symbols = null,
    ) {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
        $this->paths = new PathPolicy($project->root);
        $this->secrets = new SecretPolicy();
        $this->redactor = new Redactor();
        $this->symbols = $symbols ?? new SymbolIndex($project, $composer);
    }

    /**
     * @return array{
     *   precedence:list<string>,runtime:?string,selected_preset:?string,config_directory:?string,
     *   entries:list<ConfigEntry>,environment_variables:list<string>,referenced_classes:list<string>,diagnostics:list<Diagnostic>
     * }
     */
    public function inspect(): array
    {
        $this->diagnostics = [];
        $entries = [];

        [$runtime, $presetClass, $inline, $configDirectory] = $this->bootstrap();
        $this->append($entries, $this->foundationDefaults());
        $this->append($entries, $this->projectConfig($configDirectory));
        if ($presetClass !== null) {
            $this->append($entries, $this->preset($presetClass));
        }
        $this->append($entries, $inline);

        $this->markEffective($entries);
        $environment = [];
        $classes = [];
        foreach ($entries as $entry) {
            foreach ($entry['environment'] as $reference) {
                $environment[$reference['name']] = true;
            }
            foreach ($entry['classes'] as $class) {
                $classes[$class] = true;
            }
        }
        ksort($environment, SORT_STRING);
        ksort($classes, SORT_STRING);

        return [
            // Matches current installed ConfigRepository resolution order.
            'precedence' => ['foundation_defaults', 'project', 'preset', 'bootstrap_inline'],
            'runtime' => $runtime,
            'selected_preset' => $presetClass,
            'config_directory' => $configDirectory,
            'entries' => $entries,
            'environment_variables' => array_keys($environment),
            'referenced_classes' => array_keys($classes),
            'diagnostics' => $this->diagnostics,
        ];
    }

    /** @return list<ConfigEntry> */
    private function foundationDefaults(): array
    {
        $foundation = $this->composer->foundation();
        if ($foundation?->installPath === null) {
            $this->diagnostic('config_contract_missing', null, null, 'Installed Foundation config contract is unavailable.');
            return [];
        }

        $contract = $this->packageFile('infocyph/foundation', 'src/Config/ConfigLoader.php');
        if ($contract === null) {
            return [];
        }
        $nodes = $this->parse($contract, 'infocyph/foundation:src/Config/ConfigLoader.php');
        if ($nodes === null) {
            return [];
        }

        $classes = $this->defaultClasses($nodes);
        $entries = [];
        foreach ($classes as $class) {
            $path = $this->packageClassFile('infocyph/foundation', $class);
            if ($path === null) {
                $this->diagnostic('config_default_source_missing', null, null, sprintf('Foundation default source "%s" could not be resolved.', $class));
                continue;
            }
            $source = 'infocyph/foundation:'.$this->relativePackagePath('infocyph/foundation', $path);
            $this->append($entries, $this->methodEntries($path, $source, $class, 'all', 'foundation_defaults', 'infocyph/foundation'));
        }

        return $entries;
    }

    /** @param list<Node\Stmt> $nodes @return list<string> */
    private function defaultClasses(array $nodes): array
    {
        $classes = [];
        foreach ($this->classes($nodes) as $class) {
            foreach ($class->getMethods() as $method) {
                if ($method->name->toString() !== 'defaults') {
                    continue;
                }
                $finder = function (Node $node) use (&$finder, &$classes): void {
                    if ($node instanceof Node\Expr\StaticCall
                        && $node->class instanceof Node\Name
                        && $node->name instanceof Node\Identifier
                        && $node->name->toString() === 'all') {
                        $classes[] = $this->resolvedName($node->class);
                    }
                    foreach ($node->getSubNodeNames() as $name) {
                        $value = $node->{$name};
                        if ($value instanceof Node) {
                            $finder($value);
                        } elseif (is_array($value)) {
                            foreach ($value as $child) {
                                if ($child instanceof Node) {
                                    $finder($child);
                                }
                            }
                        }
                    }
                };
                foreach ($method->stmts ?? [] as $statement) {
                    $finder($statement);
                }
            }
        }

        return array_values(array_unique($classes));
    }

    /** @return array{0:?string,1:?string,2:list<ConfigEntry>,3:?string} */
    private function bootstrap(): array
    {
        $relative = 'bootstrap/app.php';
        $candidate = $this->project->root.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
        if (!is_file($candidate)) {
            return [null, null, [], 'config'];
        }

        try {
            $path = $this->paths->projectFile($relative);
            $this->secrets->assertAllowed($relative);
        } catch (RuntimeException $error) {
            $this->diagnostic('bootstrap_config_invalid', $relative, null, $error->getMessage());
            return [null, null, [], null];
        }

        $nodes = $this->parse($path, $relative);
        if ($nodes === null) {
            return [null, null, [], null];
        }
        $return = $this->topLevelReturn($nodes);
        if (!$return?->expr instanceof Node\Expr\StaticCall || !$return->expr->class instanceof Node\Name) {
            $this->diagnostic('bootstrap_config_dynamic', $relative, $return?->getStartLine(), 'Bootstrap runtime/config call is not statically inspectable.');
            return [null, null, [], null];
        }

        $call = $return->expr;
        $foundation = $this->resolvedName($call->class);
        $method = $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : null;
        if ($foundation !== 'Infocyph\\Foundation\\Foundation' || $method === null) {
            return [null, null, [], null];
        }

        $runtime = in_array($method, ['web', 'cli', 'worker', 'scheduler'], true) ? $method : null;
        $preset = null;
        $configArg = $call->args[0]->value ?? null;
        if ($method === 'preset') {
            $runtime = isset($call->args[0]) ? $this->runtimeMode($call->args[0]->value) : null;
            $preset = isset($call->args[1]) ? $this->newClass($call->args[1]->value) : null;
            $configArg = $call->args[2]->value ?? null;
        }

        $entries = [];
        if ($configArg instanceof Node\Expr\Array_) {
            $evaluator = new StaticConfigEvaluator();
            $evaluator->learn($this->statementsBefore($nodes, $return));
            $entries = $this->arrayEntries($configArg, '', 'bootstrap_inline', 'project', $relative, $evaluator, true);
        } elseif ($configArg !== null) {
            $this->diagnostic('bootstrap_config_dynamic', $relative, $configArg->getStartLine(), 'Inline bootstrap config is dynamic.');
        }

        return [$runtime, $preset, $entries, $this->configDirectory($entries)];
    }

    /** @param list<ConfigEntry> $inline */
    private function configDirectory(array $inline): ?string
    {
        foreach (array_reverse($inline) as $entry) {
            if ($entry['key'] !== 'paths.config') {
                continue;
            }
            if ($entry['status'] !== 'literal' || !is_string($entry['value']) || $entry['value'] === '') {
                $this->diagnostic('config_path_dynamic', 'bootstrap/app.php', $entry['line'], 'Configured config directory is dynamic; project config files were not scanned.');
                return null;
            }
            if ($this->absolute($entry['value'])) {
                $this->diagnostic('config_path_outside_project', 'bootstrap/app.php', $entry['line'], 'Absolute config directories are not inspected outside the approved project root.');
                return null;
            }

            return trim(str_replace('\\', '/', $entry['value']), '/');
        }

        return 'config';
    }

    /** @return list<ConfigEntry> */
    private function projectConfig(?string $directory): array
    {
        if ($directory === null) {
            return [];
        }
        $root = $this->project->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);
        if (!is_dir($root)) {
            return [];
        }

        $files = glob(rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'..php') ?: [];
        sort($files, SORT_STRING);
        if (count($files) > self::MAX_FILES) {
            $files = array_slice($files, 0, self::MAX_FILES);
            $this->diagnostic(.output_limit_exceeded', $directory, null, sprintf('Config inspection is limited to %d project files.', self::MAX_FILES));
        }

        $entries = [];
        foreach ($files as $file) {
            $namespace = pathinfo($file, PATHINFO_FILENAME);
            if ($namespace === '' || str_starts_with($namespace, '_')) {
                continue;
            }
            $relative = trim($directory, '/').'/'.basename($file);
            try {
                $path = $this->paths->projectFile($relative);
                $this->secrets->assertAllowed($relative);
            } catch (RuntimeException $error) {
                $this->diagnostic('config_source_invalid', $relative, null, $error->getMessage());
                continue;
            }
            $this->append($entries, $this->fileEntries($path, $relative, $namespace, 'project', 'project'));
        }

        return $entries;
    }

    /** @return list<ConfigEntry> */
    private function preset(string $class): array
    {
        $matches = $this->symbols->find($class);
        if (count($matches) === 1 && isset($matches[0]['path'])) {
            $relative = $matches[0]['path'];
            try {
                $path = $this->paths->projectFile($relative);
                return $this->methodEntries($path, $relative, $class, 'config', 'preset', 'project');
            } catch (RuntimeException $error) {
                $this->diagnostic('preset_source_invalid', $relative, null, $error->getMessage());
                return [];
            }
        }

        foreach ($this->composer->packages() as $package) {
            if ($package->installPath === null) {
                continue;
            }
            $path = $this->packageClassFile($package->name, $class);
            if ($path === null) {
                continue;
            }
            $source = $package->name.':'.$this->relativePackagePath($package->name, $path);
            return $this->methodEntries($path, $source, $class, 'config', 'preset', $package->name);
        }

        $this->diagnostic('preset_source_missing', 'bootstrap/app.php', null, sprintf('Selected preset "%s" could not be resolved statically.', $class));
        return [];
    }

    /** @return list<ConfigEntry> */
    private function fileEntries(string $path, string $source, string $namespace, string $layer, string $owner): array
    {
        $nodes = $this->parse($path, $source);
        if ($nodes === null) {
            return [];
        }
        $return = $this->topLevelReturn($nodes);
        if (!$return?->expr instanceof Node\Expr\Array_) {
            $this->diagnostic('config_dynamic', $source, $return?>->getStartLine(), 'Config file return value is not a statically inspectable array.');
            return [];
        }

        $evaluator = new StaticConfigEvaluator();
        $evaluator->learn($this->statementsBefore($nodes, $return));
        return $this->arrayEntries($return->expr, $namespace, $layer, $owner, $source, $evaluator);
    }

    /** @return list<ConfigEntry> */
    private function methodEntries(
        string $path,
        string $source,
        string $className,
        string $methodName,
        string $layer,
        string $owner,
    ): array {
        $nodes = $this->parse($path, $source);
        if ($nodes === null) {
            return [];
        }
        foreach ($this->classes($nodes) as $class) {
            $name = $class->namespacedName?>->toString() ?? $class->name?>->toString();
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
                $evaluator = new StaticConfigEvaluator();
                $evaluator->learn($method->stmts ?? []);
                return $this->arrayEntries($return->expr, '', $layer, $owner, $source, $evaluator);
            }
        }

        return [];
    }

    /** @return list<ConfigEntry> */
    private function arrayEntries(
        Node\Expr\Array_ $array,
        string $prefix,
        string $layer,
        string $owner,
        string $source,
        StaticConfigEvaluator $evaluator,
        bool $normalizeBootstrap = false,
    ): array {
        $entries = [];
        $nextIndex = 0;
        foreach ($array->items as $item) {
            if (count($entries) >= self::MAX_ENTRIES) {
                $this->diagnostic(.output_limit_exceeded', $source, $array->getStartLine(), sprintf('Config inspection is limited to %d entries per source expansion.', self::MAX_ENTRIES));
                break;
            }
            if (!$item instanceof Node\Expr\ArrayItem || $item->unpack) {
                $this->diagnostic('config_dynamic', $source, $array->getStartLine(), 'Config array contains unsupported dynamic/unpacked syntax.');
                continue;
            }
            $key = $this->arrayKey($item, $nextIndex);
            if ($key === null) {
                $this->diagnostic('config_dynamic', $source, $item->getStartLine(), 'Config array key is dynamic.');
                continue;
            }
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if ($normalizeBootstrap && $prefix === '' && in_array((string) $key, ['base_path', 'env', 'debug'], true)) {
                $path = 'app.'.$key;
            }
            if ($normalizeBootstrap && str_starts_with($path, '_')) {
                continue;
            }

            if ($item->value instanceof Node\Expr\Array_) {
                if ($item->value->items === []) {
                    $entries[] = [
                        'key' => $path,
                        'layer' => $layer,
                        'value' => [],
                        'status' => 'literal',
                        'environment' => [],
                        'classes' => [],
                        'owner' => $owner,
                        'source' => $source,
                        'line' => $item->getStartLine(),
                        'effective' => false,
                    ];
                } else {
                    $this->append($entries, $this->arrayEntries($item->value, $path, $layer, $owner, $source, $evaluator, $normalizeBootstrap));
                }
                continue;
            }

            $result = $evaluator->evaluate($item->value);
            $entries[] = [
                'key' => $path,
                'layer' => $layer,
                'value' => $result['status'] === 'literal' ? $this->sanitize($path, $result['value']) : null,
                'status' => $result['status'],
                'environment' => $this->sanitizeEnvironment($path, $result['environment']),
                'classes' => array_slice($result['classes'], 0, 32),
                'owner' => $owner,
                'source' => $source,
                'line' => $item->getStartLine(),
                'effective' => false,
            ];
        }

        return $entries;
    }

    /** @param list<ConfigEntry> $entries */
    private function markEffective(array &$entries): void
    {
        $seen = [];
        for ($index = count($entries) - 1; $index >= 0; --$index) {
            $key = $entries[$index]['key'];
            if (!isset($seen[$key])) {
                $entries[$index]['effective'] = true;
                $seen[$key] = true;
            }
        }
    }

    /** @param list<ConfigEntry> $target @param list<ConfigEntry> $items */
    private function append(array &$target, array $items): void
    {
        $remaining = self::MAX_ENTRIES - count($target);
        if ($remaining <= 0) {
            return;
        }
        array_push($target, ...array_slice($items, 0, $remaining));
        if (count($items) > $remaining) {
            $this->diagnostic('output_limit_exceeded', null, null, sprintf('Config inspection is limited to %d entries.', self::MAX_ENTRIES));
        }
    }

    /** @return int|string|null */
    private function arrayKey(Node\Expr\ArrayItem $item, int &$nextIndex): int|string|null
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

    /** @param list<array{name:string,helper:string,has_default:bool,default:mixed}> $environment */
    private function sanitizeEnvironment(string $key, array $environment): array
    {
        foreach ($environment as &$reference) {
            if ($reference['has_default']) {
                $reference['default'] = $this->sanitize($key.'.'.$reference['name'], $reference['default']);
            }
        }
        unset($reference);

        return $environment;
    }

    private function sanitize(string $key, mixed $value): mixed
    {
        if (preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
            return '[REDACTED]';
        }
        if (is_string($value)) {
            return $this->redactor->redact($value);
        }

        return $value;
    }

    /** @return list<Node\Stmt> */
    private function parse(string $path, string $source): ?array
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

    /** @param list<Node\Stmt> $nodes @return list<Node\Stmt\Class_> */
    private function classes(array $nodes): array
    {
        $classes = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                foreach ($node->stmts as $statement) {
                    if ($statement instanceof Node\Stmt\Class_) {
                        $classes[] = $statement;
                    }
                }
            } elseif ($node instanceof Node\Stmt\Class_) {
                $classes[] = $node;
            }
        }
        return $classes;
    }

    /** @param list<Node\Stmt> $nodes */
    private function topLevelReturn(array $nodes): ?Node\Stmt\Return_
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Return_) {
                return $node;
            }
            if ($node instanceof Node\Stmt\Namespace_) {
                foreach ($node->stmts as $statement) {
                    if ($statement instanceof Node\Stmt\Return_) {
                        return $statement;
                    }
                }
            }
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

    /** @param list<Node\Stmt> $nodes @return list<Node\Stmt> */
    private function statementsBefore(array $nodes, Node\Stmt\Return_ $return): array
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

    private function runtimeMode(Node\Expr $expr): ?string
    {
        if (!$expr instanceof Node\Expr\ClassConstFetch || !$expr->name instanceof Node\Identifier) {
            return null;
        }
        $name = strtolower($expr->name->toString());
        return in_array($name, ['web', 'cli', 'worker', 'scheduler'], true) ? $name : null;
    }

    private function newClass(Node\Expr $expr): ?string
    {
        if (!$expr instanceof Node\Expr\New_ || !$expr->class instanceof Node\Name) {
            return null;
        }
        return $this->resolvedName($expr->class);
    }

    private function packageClassFile(string $packageName, string $class): ?string
    {
        $package = $this->composer->package($packageName);
        if ($package?->installPath === null) {
            return null;
        }
        $psr4 = $package->autoload['psr-4'] ?? null;
        if (!is_array($psr4)) {
            return null;
        }

        foreach ($psr4 as $prefix => $directories) {
            if (!is_string($prefix) || !str_starts_with($class, $prefix)) {
                continue;
            }
            foreach (is_array($directories) ? $directories : [$directories] as $directory) {
                if (!is_string($directory)) {
                    continue;
                }
                $relative = trim(str_replace('\\', '/', $directory), '/').'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                $path = $this->packageFile($packageName, $relative);
                if ($path !== null && is_file($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    private function packageFile(string $package, string $relative): ?string
    {
        $roots = $this->composer->packageRoots([$package]);
        $root = $roots[$package] ?? null;
        if ($root === null) {
            return null;
        }
        try {
            return (new PathPolicy($this->project->root, [$package => $root]))->packageFile($package, $relative);
        } catch (RuntimeException $error) {
            $this->diagnostic('package_source_invalid', $package.':'.$relative, null, $error->getMessage());
            return null;
        }
    }

    private function relativePackagePath(string $package, string $path): string
    {
        $root = $this->composer->packageRoots([$package])[$package] ?? '';
        $root = str_replace('\\', '/', rtrim($root, '/\\'));
        $path = str_replace('\\', '/', $path);
        return ltrim(substr($path, strlen($root)), '/');
    }

    private function resolvedName(Node\Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');
        return ltrim(($resolved instanceof Node\Name ? $resolved : $name)->toString(), '\\');
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    private function diagnostic(string $code, ?string $source, ?int $line, string $message): void
    {
        if (count($this->diagnostics) < self::MAX_DIAGNOSTICS) {
            $this->diagnostics[] = compact('code', 'source', 'line', 'message');
        }
    }
}
