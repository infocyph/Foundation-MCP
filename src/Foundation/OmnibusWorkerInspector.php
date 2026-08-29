<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation;

use Infocyph\FoundationMcp\Analysis\Internal\PhpNodeBudgetVisitor;
use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Foundation\Internal\ConfigEntryExtractor;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Security\PathPolicy;
use Infocyph\FoundationMcp\Security\Redactor;
use Infocyph\FoundationMcp\Security\SecretPolicy;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;

/** Static inspector for Omnibus messaging-worker configuration. */
final class OmnibusWorkerInspector
{
    private const int MAX_DIAGNOSTICS = 100;

    private const int MAX_OPTION_FIELDS = 64;

    private const int MAX_SETTINGS = 1_000;

    private const int MAX_SOURCE_BYTES = 1_048_576;

    private const int MAX_TYPE_DEPTH = 32;

    private const int MAX_WORKERS = 250;

    private const string OPTIONS_SOURCE = 'src/Consumer/WorkerOptions.php';

    private const string PACKAGE = 'infocyph/omnibus';

    private const string SOURCE = 'config/messaging.php';

    private readonly Parser $parser;

    private readonly SecretPolicy $secrets;

    /** @var list<array{code:string,source:?string,line:?int,message:string}> */
    private array $diagnostics = [];

    public function __construct(
        private readonly Project $project,
        private readonly ComposerInspector $composer,
        ?Parser $parser = null,
    ) {
        $this->parser = $parser ?? new ParserFactory()->createForNewestSupportedVersion();
        $this->secrets = new SecretPolicy();
    }

    /**
     * @return array{
     *   source:string,category:string,package:string,package_version:?string,package_state:string,
     *   option_fields:list<array{name:string,type:?string,has_default:bool}>,
     *   workers:list<array{name:string,settings:array<string,array{value:mixed,status:string,environment:array,classes:array,line:int}>,source:string,status:string}>,
     *   diagnostics:list<array{code:string,source:?string,line:?int,message:string}>
     * }
     */
    public function inspect(): array
    {
        $this->diagnostics = [];
        $package = $this->composer->package(self::PACKAGE);
        $version = $package?->installedVersion ?? $package?->lockedVersion;
        $state = $package?->state() ?? 'missing';
        $optionFields = $this->optionFields();
        $workers = [];

        $candidate = $this->project->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::SOURCE);
        if (is_file($candidate)) {
            try {
                $this->secrets->assertAllowed(self::SOURCE);
                $path = new PathPolicy($this->project->root)->projectFile(self::SOURCE);
                $extractor = new ConfigEntryExtractor($this->parser, new Redactor());
                $entries = $extractor->file($path, self::SOURCE, 'messaging', 'project', 'project');
                $this->mergeDiagnostics($extractor->diagnostics());
                $workers = $this->groupEntries($entries);
            } catch (RuntimeException $error) {
                $this->diagnostic('omnibus_worker_source_invalid', self::SOURCE, null, $error->getMessage());
            }
        }

        return [
            'source' => self::SOURCE,
            'category' => 'omnibus_messaging',
            'package' => self::PACKAGE,
            'package_version' => $version,
            'package_state' => $state,
            'option_fields' => $optionFields,
            'workers' => $workers,
            'diagnostics' => $this->diagnostics,
        ];
    }

    private function diagnostic(string $code, ?string $source, ?int $line, string $message): void
    {
        $count = count($this->diagnostics);
        if ($count >= self::MAX_DIAGNOSTICS) {
            return;
        }
        if ($count === self::MAX_DIAGNOSTICS - 1 && $code !== 'diagnostic_limit_exceeded') {
            $this->diagnostics[] = [
                'code' => 'diagnostic_limit_exceeded',
                'source' => null,
                'line' => null,
                'message' => sprintf('Omnibus worker diagnostics are limited to %d entries.', self::MAX_DIAGNOSTICS),
            ];

            return;
        }

        $this->diagnostics[] = [
            'code' => $code,
            'source' => $source,
            'line' => $line,
            'message' => $message,
        ];
    }

    private function diagnosticOnce(string $code, ?string $source, ?int $line, string $message): void
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic['code'] === $code && $diagnostic['source'] === $source) {
                return;
            }
        }

        $this->diagnostic($code, $source, $line, $message);
    }

    /** @param list<array{key:string,layer:string,value:mixed,status:string,environment:array,classes:array,owner:string,source:string,line:int,effective:bool}> $entries */
    private function groupEntries(array $entries): array
    {
        $groups = [];
        $settingsCount = 0;
        foreach ($entries as $entry) {
            $segments = explode('.', $entry['key']);
            $worker = $this->workerSegment($segments);
            if ($worker === null) {
                continue;
            }
            [$workerIndex, $plural] = $worker;
            $name = $plural ? ($segments[$workerIndex + 1] ?? 'default') : 'default';
            if ($name === '') {
                $name = 'default';
            }
            $settingSegments = array_slice($segments, $workerIndex + ($plural ? 2 : 1));
            $setting = $settingSegments === [] ? 'value' : implode('.', $settingSegments);
            if (!isset($groups[$name]) && count($groups) >= self::MAX_WORKERS) {
                $this->diagnostic('output_limit_exceeded', self::SOURCE, $entry['line'], sprintf('Omnibus workers are limited to %d groups.', self::MAX_WORKERS));

                break;
            }
            if ($settingsCount >= self::MAX_SETTINGS) {
                $this->diagnostic('output_limit_exceeded', self::SOURCE, $entry['line'], sprintf('Omnibus worker settings are limited to %d entries.', self::MAX_SETTINGS));

                break;
            }
            $groups[$name] ??= ['name' => $name, 'settings' => [], 'source' => self::SOURCE, 'status' => 'resolved'];
            $groups[$name]['settings'][$setting] = [
                'value' => $entry['value'],
                'status' => $entry['status'],
                'environment' => $entry['environment'],
                'classes' => $entry['classes'],
                'line' => $entry['line'],
            ];
            if ($entry['status'] !== 'literal') {
                $groups[$name]['status'] = 'dynamic';
            }
            ++$settingsCount;
        }
        ksort($groups, SORT_STRING);
        foreach ($groups as &$group) {
            ksort($group['settings'], SORT_STRING);
        }
        unset($group);

        return array_values($groups);
    }

    /** @param list<array{code:string,source:?string,line:?int,message:string}> $diagnostics */
    private function mergeDiagnostics(array $diagnostics): void
    {
        foreach ($diagnostics as $diagnostic) {
            $this->diagnostic(
                $diagnostic['code'],
                $diagnostic['source'],
                $diagnostic['line'],
                $diagnostic['message'],
            );
        }
    }

    /** @return list<array{name:string,type:?string,has_default:bool}> */
    private function optionFields(): array
    {
        $roots = $this->composer->packageRoots([self::PACKAGE]);
        if (!isset($roots[self::PACKAGE])) {
            $this->diagnostic('omnibus_package_unavailable', self::OPTIONS_SOURCE, null, 'Installed Omnibus source is unavailable; worker option schema cannot be derived.');

            return [];
        }

        try {
            $paths = new PathPolicy($this->project->root, $roots);
            $path = $paths->packageFile(self::PACKAGE, self::OPTIONS_SOURCE);
        } catch (RuntimeException $error) {
            $this->diagnostic('omnibus_worker_contract_unavailable', self::OPTIONS_SOURCE, null, $error->getMessage());

            return [];
        }
        $source = $this->read($path);
        if ($source === null) {
            $this->diagnostic(
                'omnibus_worker_contract_unreadable',
                self::OPTIONS_SOURCE,
                null,
                sprintf('WorkerOptions source is unreadable, binary, invalid UTF-8, or exceeds %d bytes.', self::MAX_SOURCE_BYTES),
            );

            return [];
        }

        try {
            $nodes = $this->parser->parse($source) ?? [];
        } catch (Error $error) {
            $this->diagnostic('parse_error', self::OPTIONS_SOURCE, $error->getStartLine(), $error->getRawMessage());

            return [];
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new PhpNodeBudgetVisitor());

        try {
            /** @var list<Node\Stmt> $nodes */
            $nodes = $traverser->traverse($nodes);
        } catch (RuntimeException $error) {
            $this->diagnostic('inspection_limit_exceeded', self::OPTIONS_SOURCE, null, $error->getMessage());

            return [];
        }

        foreach ($nodes as $node) {
            foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $statement) {
                if (!$statement instanceof Node\Stmt\Class_) {
                    continue;
                }
                foreach ($statement->getMethods() as $method) {
                    if (strtolower($method->name->toString()) !== '__construct') {
                        continue;
                    }
                    $fields = [];
                    foreach ($method->params as $param) {
                        if (!$param->flags || !$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                            continue;
                        }
                        if (count($fields) >= self::MAX_OPTION_FIELDS) {
                            $this->diagnosticOnce(
                                'output_limit_exceeded',
                                self::OPTIONS_SOURCE,
                                $param->getStartLine(),
                                sprintf('WorkerOptions schema is limited to %d promoted fields.', self::MAX_OPTION_FIELDS),
                            );

                            break;
                        }
                        $fields[] = [
                            'name' => $param->var->name,
                            'type' => $this->typeName($param->type),
                            'has_default' => $param->default !== null,
                        ];
                    }

                    return $fields;
                }
            }
        }
        $this->diagnostic('omnibus_worker_contract_dynamic', self::OPTIONS_SOURCE, null, 'WorkerOptions constructor could not be inspected statically.');

        return [];
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

    private function typeName(Node\Identifier|Node\Name|Node\ComplexType|null $type, int $depth = 0): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($depth >= self::MAX_TYPE_DEPTH) {
            $this->diagnosticOnce(
                'inspection_limit_exceeded',
                self::OPTIONS_SOURCE,
                $type->getStartLine(),
                sprintf('WorkerOptions type inspection is limited to %d levels.', self::MAX_TYPE_DEPTH),
            );

            return null;
        }
        if ($type instanceof Node\NullableType) {
            $inner = $this->typeName($type->type, $depth + 1);

            return $inner === null ? null : '?' . $inner;
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $separator = $type instanceof Node\UnionType ? '|' : '&';
            $parts = [];
            foreach ($type->types as $part) {
                $parts[] = $this->typeName($part, $depth + 1) ?? 'mixed';
            }

            return implode($separator, $parts);
        }

        return $type->toString();
    }

    /** @param list<string> $segments @return array{int,bool}|null */
    private function workerSegment(array $segments): ?array
    {
        foreach ($segments as $index => $segment) {
            if ($segment === 'worker') {
                return [$index, false];
            }
            if ($segment === 'workers') {
                return [$index, true];
            }
        }

        return null;
    }
}
