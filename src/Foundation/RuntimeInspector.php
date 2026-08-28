<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation;

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Foundation\Internal\ConfigEntryExtractor;
use Infocyph\FoundationMcp\Foundation\Internal\InstalledRuntimeContract;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Security\PathPolicy;
use Infocyph\FoundationMcp\Security\Redactor;
use Infocyph\FoundationMcp\Security\SecretPolicy;
use PhpParser\Node;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;

/**
 * @phpstan-type ConfigEntry array{key:string,layer:string,value:mixed,status:string,environment:list<array{name:string,helper:string,has_default:bool,default:mixed}>,classes:list<string>,owner:string,source:string,line:int,effective:bool}
 * @phpstan-type Diagnostic array{code:string,source:?string,line:?int,message:string}
 */
final class RuntimeInspector
{
    private const array GRAPHS = ['web', 'cli', 'worker', 'scheduler'];

    private const int MAX_INLINE_OPTIONS = 512;

    private readonly ConfigEntryExtractor $extractor;

    private readonly Parser $parser;

    private readonly PathPolicy $paths;

    private readonly SecretPolicy $secrets;

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    public function __construct(
        private readonly Project $project,
        private readonly ComposerInspector $composer,
        ?Parser $parser = null,
    ) {
        $this->parser = $parser ?? new ParserFactory()->createForNewestSupportedVersion();
        $this->paths = new PathPolicy($project->root);
        $this->secrets = new SecretPolicy();
        $this->extractor = new ConfigEntryExtractor($this->parser, new Redactor());
    }

    /**
     * @return array{
     *   bootstrap_file:string,runtime:?string,runtime_method:?string,runtime_status:string,
     *   runtime_graphs:array<string,array{selected:bool,available:bool}>,selected_preset:?string,
     *   base_path:?string,base_path_status:string,inline_options:list<ConfigEntry>,source_line:?int,
     *   diagnostics:list<Diagnostic>
     * }
     */
    public function inspect(): array
    {
        $this->diagnostics = [];
        $contract = new InstalledRuntimeContract($this->project, $this->composer, $this->parser)->read();
        $this->diagnostics = $contract['diagnostics'];
        $relative = 'bootstrap/app.php';

        $result = [
            'bootstrap_file' => $relative,
            'runtime' => null,
            'runtime_method' => null,
            'runtime_status' => 'missing',
            'runtime_graphs' => $this->graphs(null, $contract['methods']),
            'selected_preset' => null,
            'base_path' => null,
            'base_path_status' => 'missing',
            'inline_options' => [],
            'source_line' => null,
            'diagnostics' => [],
        ];

        if (!is_file($this->project->root . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php')) {
            $this->diagnostic('bootstrap_missing', $relative, null, 'Project bootstrap/app.php is missing.');
            $result['diagnostics'] = $this->allDiagnostics();

            return $result;
        }

        try {
            $path = $this->paths->projectFile($relative);
            $this->secrets->assertAllowed($relative);
        } catch (RuntimeException $error) {
            $this->diagnostic('bootstrap_invalid', $relative, null, $error->getMessage());
            $result['runtime_status'] = 'invalid';
            $result['diagnostics'] = $this->allDiagnostics();

            return $result;
        }

        $nodes = $this->extractor->parse($path, $relative);
        $return = $nodes === null ? null : $this->extractor->topLevelReturn($nodes);
        if (!$return?->expr instanceof Node\Expr\StaticCall || !$return->expr->class instanceof Node\Name) {
            $this->diagnostic('runtime_dynamic', $relative, $return?->getStartLine(), 'Bootstrap runtime selection is not a statically inspectable Foundation call.');
            $result['runtime_status'] = 'dynamic';
            $result['diagnostics'] = $this->allDiagnostics();

            return $result;
        }

        $call = $return->expr;
        $class = $this->resolvedName($call->class);
        if ($class !== 'Infocyph\\Foundation\\Foundation' || !$call->name instanceof Node\Identifier) {
            $this->diagnostic('runtime_dynamic', $relative, $call->getStartLine(), 'Bootstrap return does not select an installed Foundation runtime entry point.');
            $result['runtime_status'] = 'dynamic';
            $result['diagnostics'] = $this->allDiagnostics();

            return $result;
        }

        $method = strtolower($call->name->toString());
        $runtime = $contract['methods'][$method] ?? null;
        $preset = null;
        $config = $call->args[0]->value ?? null;
        if ($method === 'preset' && $contract['preset']) {
            $runtime = isset($call->args[0]) ? $this->runtimeMode($call->args[0]->value) : null;
            $preset = isset($call->args[1]) ? $this->presetClass($call->args[1]->value) : null;
            $config = $call->args[2]->value ?? null;
        }

        $result['runtime'] = in_array($runtime, self::GRAPHS, true) ? $runtime : null;
        $result['runtime_method'] = $method;
        $result['runtime_status'] = $result['runtime'] === null ? 'dynamic' : 'resolved';
        $result['runtime_graphs'] = $this->graphs($result['runtime'], $contract['methods']);
        $result['selected_preset'] = $preset;
        $result['source_line'] = $call->getStartLine();

        if ($result['runtime'] === null) {
            $this->diagnostic('runtime_dynamic', $relative, $call->getStartLine(), sprintf('Runtime entry point "%s" could not be resolved from the installed Foundation contract.', $method));
        }
        if ($method === 'preset' && $preset === null) {
            $this->diagnostic('runtime_preset_dynamic', $relative, $call->getStartLine(), 'Foundation preset selection is dynamic.');
        }

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
            if (count($entries) > self::MAX_INLINE_OPTIONS) {
                $entries = array_slice($entries, 0, self::MAX_INLINE_OPTIONS);
                $this->diagnostic('output_limit_exceeded', $relative, $config->getStartLine(), sprintf('Inline bootstrap inspection is limited to %d flattened options.', self::MAX_INLINE_OPTIONS));
            }
            $result['inline_options'] = $entries;
            [$result['base_path'], $result['base_path_status']] = $this->basePath($config);
        } elseif ($config !== null) {
            $result['base_path_status'] = 'dynamic';
            $this->diagnostic('bootstrap_config_dynamic', $relative, $config->getStartLine(), 'Inline bootstrap config is dynamic.');
        }

        $result['diagnostics'] = $this->allDiagnostics();

        return $result;
    }

    private function absolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/^[A-Za-z]:\//', $path) === 1;
    }

    /** @return list<Diagnostic> */
    private function allDiagnostics(): array
    {
        return array_slice([...$this->diagnostics, ...$this->extractor->diagnostics()], 0, 100);
    }

    /** @return array{0:?string,1:string} */
    private function basePath(Node\Expr\Array_ $config): array
    {
        foreach ($config->items as $item) {
            if (!$item instanceof Node\Expr\ArrayItem || !$item->key instanceof Node\Scalar\String_ || $item->key->value !== 'base_path') {
                continue;
            }
            $value = $item->value;
            if ($this->projectRootExpression($value)) {
                return ['project_root', 'project_root'];
            }
            if ($value instanceof Node\Scalar\String_) {
                $literal = str_replace('\\', '/', $value->value);
                if ($literal !== '' && !$this->absolute($literal) && !in_array('..', explode('/', $literal), true)) {
                    return [trim($literal, '/'), 'literal_relative'];
                }
                $this->diagnostic('base_path_outside_project', 'bootstrap/app.php', $value->getStartLine(), 'Absolute or parent-traversing bootstrap base paths are not exposed.');

                return [null, 'denied'];
            }

            return [null, 'dynamic'];
        }

        return [null, 'missing'];
    }

    private function diagnostic(string $code, ?string $source, ?int $line, string $message): void
    {
        if (count($this->diagnostics) < 100) {
            $this->diagnostics[] = compact('code', 'source', 'line', 'message');
        }
    }

    /** @param array<string,string> $methods @return array<string,array{selected:bool,available:bool}> */
    private function graphs(?string $selected, array $methods): array
    {
        $available = array_fill_keys(array_values($methods), true);
        $graphs = [];
        foreach (self::GRAPHS as $graph) {
            $graphs[$graph] = ['selected' => $selected === $graph, 'available' => isset($available[$graph])];
        }

        return $graphs;
    }

    private function presetClass(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            return $this->resolvedName($expr->class);
        }
        if ($expr instanceof Node\Expr\ClassConstFetch && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier
            && strtolower($expr->name->toString()) === 'class') {
            return $this->resolvedName($expr->class);
        }

        return null;
    }

    private function projectRootExpression(Node\Expr $expr): bool
    {
        if (!$expr instanceof Node\Expr\FuncCall || !$expr->name instanceof Node\Name || strtolower($expr->name->toString()) !== 'dirname') {
            return false;
        }
        $first = $expr->args[0]->value ?? null;
        if ($first instanceof Node\Scalar\MagicConst\Dir) {
            $levels = $expr->args[1]->value ?? null;

            return $levels === null || ($levels instanceof Node\Scalar\Int_ && $levels->value === 1);
        }
        if ($first instanceof Node\Scalar\MagicConst\File) {
            $levels = $expr->args[1]->value ?? null;

            return $levels instanceof Node\Scalar\Int_ && $levels->value === 2;
        }

        return false;
    }

    private function resolvedName(Node\Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return ltrim(($resolved instanceof Node\Name ? $resolved : $name)->toString(), '\\');
    }

    private function runtimeMode(Node\Expr $expr): ?string
    {
        if (!$expr instanceof Node\Expr\ClassConstFetch || !$expr->class instanceof Node\Name || !$expr->name instanceof Node\Identifier) {
            return null;
        }
        if ($this->resolvedName($expr->class) !== 'Infocyph\\Foundation\\Application\\RuntimeMode') {
            return null;
        }
        $value = strtolower($expr->name->toString());

        return in_array($value, self::GRAPHS, true) ? $value : null;
    }
}
