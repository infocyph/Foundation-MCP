<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation;

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

/** Static inspector for Omnibus messaging-worker configuration. */
final class OmnibusWorkerInspector
{
    private const int MAX_DIAGNOSTICS = 100;

    private const int MAX_SETTINGS = 1_000;

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
                $this->diagnostics = [...$this->diagnostics, ...array_slice($extractor->diagnostics(), 0, self::MAX_DIAGNOSTICS)];
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
            'diagnostics' => array_slice($this->diagnostics, 0, self::MAX_DIAGNOSTICS),
        ];
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
        $source = file_get_contents($path);
        if (!is_string($source) || strlen($source) > 1_048_576 || str_contains($source, "\0")) {
            $this->diagnostic('omnibus_worker_contract_unreadable', self::OPTIONS_SOURCE, null, 'WorkerOptions source is unreadable, binary, or too large.');

            return [];
        }

        try {
            $nodes = $this->parser->parse($source) ?? [];
        } catch (\PhpParser\Error $error) {
            $this->diagnostic('parse_error', self::OPTIONS_SOURCE, $error->getStartLine(), $error->getMessage());

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
                        $fields[] = [
                            'name' => $param->var->name,
                            'type' => $this->typeName($param->type),
                            'has_default' => $param->default !== null,
                        ];
                    }

                    return array_slice($fields, 0, 64);
                }
            }
        }
        $this->diagnostic('omnibus_worker_contract_dynamic', self::OPTIONS_SOURCE, null, 'WorkerOptions constructor could not be inspected statically.');

        return [];
    }

    private function typeName(Node\Identifier|Node\Name|Node\ComplexType|null $type): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof Node\NullableType) {
            return '?' . $this->typeName($type->type);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $separator = $type instanceof Node\UnionType ? '|' : '&';

            return implode($separator, array_map(fn($part): string => $this->typeName($part) ?? 'mixed', $type->types));
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
