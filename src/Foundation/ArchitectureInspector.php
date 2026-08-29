<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation;

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Project\SourceRoots;
use Throwable;

final readonly class ArchitectureInspector
{
    private const int MAX_DIAGNOSTICS = 50;

    public function __construct(
        private Project $project,
        private ComposerInspector $composer,
        private ModuleCatalogReader $modules,
        private ProviderInspector $providers,
        private RuntimeInspector $runtime,
    ) {}

    /** @return array<string,mixed> */
    public function inspect(): array
    {
        $diagnostics = [];
        $modules = [];
        $providers = [];
        $sourceRoots = ['application' => [], 'tests' => [], 'structural' => []];

        try {
            $modules = $this->modules->modules();
        } catch (Throwable $error) {
            $this->diagnostic($diagnostics, 'module_catalog_invalid', $error->getMessage());
        }

        try {
            $providerInspection = $this->providers->inspect();
            $providers = $providerInspection['effective'] ?? [];
            foreach ($providerInspection['diagnostics'] ?? [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $this->diagnostic(
                    $diagnostics,
                    is_string($item['code'] ?? null) ? $item['code'] : 'provider_diagnostic',
                    is_string($item['message'] ?? null) ? $item['message'] : 'Provider inspection reported an unspecified diagnostic.',
                    is_string($item['source'] ?? null) ? $item['source'] : null,
                    is_int($item['line'] ?? null) ? $item['line'] : null,
                );
            }
        } catch (Throwable $error) {
            $this->diagnostic($diagnostics, 'provider_inspection_failed', $error->getMessage());
        }

        try {
            $roots = SourceRoots::discover($this->project);
            $sourceRoots = [
                'application' => $this->relativeRoots($roots->application),
                'tests' => $this->relativeRoots($roots->tests),
                'structural' => $this->relativeRoots($roots->structural),
            ];
        } catch (Throwable $error) {
            $this->diagnostic($diagnostics, 'source_root_inspection_failed', $error->getMessage());
        }

        return [
            'kind' => 'architecture',
            'host_type' => $this->project->hostType->value,
            'runtime' => $this->runtime->inspect(),
            'modules' => $modules,
            'provider_graphs' => $providers,
            'dependencies' => [
                'runtime_direct' => $this->composer->graph()->runtimeDirect(),
                'dev_direct' => $this->composer->graph()->devDirect(),
            ],
            'source_roots' => $sourceRoots,
            'diagnostics' => $diagnostics,
        ];
    }

    /** @param list<array{code:string,message:string,source?:?string,line?:?int}> $diagnostics */
    private function diagnostic(
        array &$diagnostics,
        string $code,
        string $message,
        ?string $source = null,
        ?int $line = null,
    ): void {
        $count = count($diagnostics);
        if ($count >= self::MAX_DIAGNOSTICS) {
            return;
        }
        if ($count === self::MAX_DIAGNOSTICS - 1 && $code !== 'diagnostic_limit_exceeded') {
            $diagnostics[] = [
                'code' => 'diagnostic_limit_exceeded',
                'message' => sprintf('Architecture diagnostics are limited to %d entries.', self::MAX_DIAGNOSTICS),
            ];

            return;
        }

        $diagnostic = ['code' => $code, 'message' => $message];
        if ($source !== null) {
            $diagnostic['source'] = $source;
        }
        if ($line !== null) {
            $diagnostic['line'] = $line;
        }
        $diagnostics[] = $diagnostic;
    }

    /** @param list<string> $roots @return list<string> */
    private function relativeRoots(array $roots): array
    {
        $projectRoot = str_replace('\\', '/', rtrim($this->project->root, '/\\'));
        $result = [];

        foreach ($roots as $root) {
            $root = str_replace('\\', '/', rtrim($root, '/\\'));
            $result[] = $root === $projectRoot ? '.' : substr($root, strlen($projectRoot) + 1);
        }

        sort($result, SORT_STRING);

        return array_values(array_unique($result));
    }
}
