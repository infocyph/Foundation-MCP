<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation;

use Infocyph\FoundationMcp\Analysis\SymbolIndex;
use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Foundation\Internal\ConfigEntryExtractor;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Security\PathPolicy;
use Infocyph\FoundationMcp\Security\Redactor;
use Infocyph\FoundationMcp\Security\SecretPolicy;
use PhpParser\Node;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;

/**
 * Static, non-bootstrapping Foundation configuration inspection.
 *
 * @phpstan-type ConfigEntry array{key:string,layer:string,value:mixed,status:string,environment:list<array{name:string,helper:string,has_default:bool,default:mixed}>,classes:list<string>,owner:string,source:string,line:int,effective:bool}
 * @phpstan-type Diagnostic array{code:string,source:?string,line:?int,message:string}
 */
final class ConfigInspector
{
    private const int MAX_DIAGNOSTICS = 100;

    private const int MAX_ENTRIES = 3_000;

    private const int MAX_FILES = 256;

    private readonly ConfigEntryExtractor $extractor;

    private readonly PathPolicy $paths;

    private readonly SecretPolicy $secrets;

    private readonly SymbolIndex $symbols;

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    public function __construct(
        private readonly Project $project,
        private readonly ComposerInspector $composer,
        ?Parser $parser = null,
        ?SymbolIndex $symbols = null,
    ) {
        $parser ??= new ParserFactory()->createForNewestSupportedVersion();
        $this->paths = new PathPolicy($project->root);
        $this->secrets = new SecretPolicy();
        $this->symbols = $symbols ?? new SymbolIndex($project, $composer);
        $this->extractor = new ConfigEntryExtractor($parser, new Redactor());
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
        [$runtime, $preset, $inline, $configDirectory] = $this->bootstrap();

        $entries = [];
        $this->append($entries, $this->foundationDefaults());
        $this->append($entries, $this->projectConfig($configDirectory));
        if ($preset !== null) {
            $this->append($entries, $this->preset($preset));
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
            'precedence' => ['foundation_defaults', 'project', 'preset', 'bootstrap_inline'],
            'runtime' => $runtime,
            'selected_preset' => $preset,
            'config_directory' => $configDirectory,
            'entries' => $entries,
            'environment_variables' => array_keys($environment),
            'referenced_classes' => array_keys($classes),
            'diagnostics' => [...$this->diagnostics, ...$this->extractor->diagnostics()],
        ];
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
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

    /** @return array{0:?string,1:?string,2:list<ConfigEntry>,3:?string} */
    private function bootstrap(): array
    {
        $relative = 'bootstrap/app.php';
        if (!is_file($this->project->root . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php')) {
            return [null, null, [], 'config'];
        }

        try {
            $path = $this->paths->projectFile($relative);
            $this->secrets->assertAllowed($relative);
        } catch (RuntimeException $error) {
            $this->diagnostic('bootstrap_config_invalid', $relative, null, $error->getMessage());

            return [null, null, [], null];
        }

        $nodes = $this->extractor->parse($path, $relative);
        $return = $nodes === null ? null : $this->extractor->topLevelReturn($nodes);
        if (!$return?->expr instanceof Node\Expr\StaticCall || !$return->expr->class instanceof Node\Name) {
            $this->diagnostic('bootstrap_config_dynamic', $relative, $return?->getStartLine(), 'Bootstrap runtime/config call is not statically inspectable.');

            return [null, null, [], null];
        }

        $call = $return->expr;
        if ($this->resolvedName($call->class) !== 'Infocyph\\Foundation\\Foundation' || !$call->name instanceof Node\Identifier) {
            return [null, null, [], null];
        }
        $method = strtolower($call->name->toString());
        $runtime = in_array($method, ['web', 'cli', 'worker', 'scheduler'], true) ? $method : null;
        $preset = null;
        $config = $call->args[0]->value ?? null;
        if ($method === 'preset') {
            $runtime = isset($call->args[0]) ? $this->runtimeMode($call->args[0]->value) : null;
            $preset = isset($call->args[1]) ? $this->newClass($call->args[1]->value) : null;
            $config = $call->args[2]->value ?? null;
        }

        $entries = [];
        if ($config instanceof Node\Expr\Array_) {
            $entries = $this->extractor->array(
                $config,
                '',
                'bootstrap_inline',
                'project',
                $relative,
                $this->extractor->statementsBefore($nodes ?? [], $return),
                true,
            );
        } elseif ($config !== null) {
            $this->diagnostic('bootstrap_config_dynamic', $relative, $config->getStartLine(), 'Inline bootstrap config is dynamic.');
        }

        return [$runtime, $preset, $entries, $this->configDirectory($entries)];
    }

    /** @param list<string> $classes */
    private function collectAllCalls(Node $node, array &$classes): void
    {
        if ($node instanceof Node\Expr\StaticCall
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'all') {
            $classes[] = $this->resolvedName($node->class);
        }
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};
            if ($value instanceof Node) {
                $this->collectAllCalls($value, $classes);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->collectAllCalls($child, $classes);
                    }
                }
            }
        }
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
            $path = trim(str_replace('\\', '/', $entry['value']), '/');
            if ($this->absolute($entry['value']) || in_array('..', explode('/', $path), true)) {
                $this->diagnostic('config_path_outside_project', 'bootstrap/app.php', $entry['line'], 'Config directories outside the approved project root are not inspected.');

                return null;
            }

            return $path;
        }

        return 'config';
    }

    /** @param list<Node\Stmt> $nodes @return list<string> */
    private function defaultClasses(array $nodes): array
    {
        $classes = [];
        foreach ($this->extractor->classes($nodes) as $class) {
            foreach ($class->getMethods() as $method) {
                if ($method->name->toString() !== 'defaults') {
                    continue;
                }
                foreach ($method->stmts ?? [] as $statement) {
                    $this->collectAllCalls($statement, $classes);
                }
            }
        }

        return array_values(array_unique($classes));
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
        }
    }

    /** @return list<ConfigEntry> */
    private function foundationDefaults(): array
    {
        $contract = $this->packageFile('infocyph/foundation', 'src/Config/ConfigLoader.php');
        if ($contract === null) {
            $this->diagnostic('config_contract_missing', null, null, 'Installed Foundation config contract is unavailable.');

            return [];
        }
        $nodes = $this->extractor->parse($contract, 'infocyph/foundation:src/Config/ConfigLoader.php');
        if ($nodes === null) {
            return [];
        }

        $entries = [];
        foreach ($this->defaultClasses($nodes) as $class) {
            $path = $this->packageClassFile('infocyph/foundation', $class);
            if ($path === null) {
                $this->diagnostic('config_default_source_missing', null, null, sprintf('Foundation default source "%s" could not be resolved.', $class));

                continue;
            }
            $source = 'infocyph/foundation:' . $this->relativePackagePath('infocyph/foundation', $path);
            $this->append($entries, $this->extractor->method($path, $source, $class, 'all', 'foundation_defaults', 'infocyph/foundation'));
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

    private function newClass(Node\Expr $expr): ?string
    {
        return $expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name
            ? $this->resolvedName($expr->class)
            : null;
    }

    private function packageClassFile(string $packageName, string $class): ?string
    {
        $package = $this->composer->package($packageName);
        $psr4 = $package?->autoload['psr-4'] ?? null;
        if ($package?->installPath === null || !is_array($psr4)) {
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
                $relative = trim(str_replace('\\', '/', $directory), '/') . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
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
        $root = $this->composer->packageRoots([$package])[$package] ?? null;
        if ($root === null) {
            return null;
        }

        try {
            return new PathPolicy($this->project->root, [$package => $root])->packageFile($package, $relative);
        } catch (RuntimeException $error) {
            $this->diagnostic('package_source_invalid', $package . ':' . $relative, null, $error->getMessage());

            return null;
        }
    }

    /** @return list<ConfigEntry> */
    private function preset(string $class): array
    {
        $matches = $this->symbols->find($class);
        if (count($matches) === 1 && isset($matches[0]['path'])) {
            try {
                $path = $this->paths->projectFile($matches[0]['path']);

                return $this->extractor->method($path, $matches[0]['path'], $class, 'config', 'preset', 'project');
            } catch (RuntimeException $error) {
                $this->diagnostic('preset_source_invalid', $matches[0]['path'], null, $error->getMessage());

                return [];
            }
        }

        foreach ($this->composer->packages() as $package) {
            if ($package->installPath === null) {
                continue;
            }
            $path = $this->packageClassFile($package->name, $class);
            if ($path !== null) {
                $source = $package->name . ':' . $this->relativePackagePath($package->name, $path);

                return $this->extractor->method($path, $source, $class, 'config', 'preset', $package->name);
            }
        }

        $this->diagnostic('preset_source_missing', 'bootstrap/app.php', null, sprintf('Selected preset "%s" could not be resolved statically.', $class));

        return [];
    }

    /** @return list<ConfigEntry> */
    private function projectConfig(?string $directory): array
    {
        if ($directory === null) {
            return [];
        }
        $root = $this->project->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
        if (!is_dir($root)) {
            return [];
        }

        $files = glob(rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_STRING);
        if (count($files) > self::MAX_FILES) {
            $files = array_slice($files, 0, self::MAX_FILES);
            $this->diagnostic('output_limit_exceeded', $directory, null, sprintf('Config inspection is limited to %d project files.', self::MAX_FILES));
        }

        $entries = [];
        foreach ($files as $file) {
            $namespace = pathinfo($file, PATHINFO_FILENAME);
            if ($namespace === '' || str_starts_with($namespace, '_')) {
                continue;
            }
            $relative = trim($directory, '/') . '/' . basename($file);

            try {
                $path = $this->paths->projectFile($relative);
                $this->secrets->assertAllowed($relative);
            } catch (RuntimeException $error) {
                $this->diagnostic('config_source_invalid', $relative, null, $error->getMessage());

                continue;
            }
            $this->append($entries, $this->extractor->file($path, $relative, $namespace, 'project', 'project'));
        }

        return $entries;
    }

    private function relativePackagePath(string $package, string $path): string
    {
        $root = $this->composer->packageRoots([$package])[$package] ?? '';
        $root = str_replace('\\', '/', rtrim($root, '/\\'));

        return ltrim(substr(str_replace('\\', '/', $path), strlen($root)), '/');
    }

    private function resolvedName(Node\Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return ltrim(($resolved instanceof Node\Name ? $resolved : $name)->toString(), '\\');
    }

    private function runtimeMode(Node\Expr $expr): ?string
    {
        if (!$expr instanceof Node\Expr\ClassConstFetch || !$expr->name instanceof Node\Identifier) {
            return null;
        }
        $name = strtolower($expr->name->toString());

        return in_array($name, ['web', 'cli', 'worker', 'scheduler'], true) ? $name : null;
    }
}
