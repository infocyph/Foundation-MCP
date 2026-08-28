<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation;

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Project\SourceRoots;
use Throwable;

final readonly class ArchitectureInspector
{
    public function __construct(
        private Project $project,
        private ComposerInspector $composer,
        private ModuleCatalogReader $modules,
        private ProviderInspector $providers,
        private RuntimeInspector $runtime,
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(): array
    {
        $diagnostics = [];
        $modules = [];
        $providers = [];

        try {
            $modules = $this->modules->modules();
        } catch (Throwable $error) {
            $diagnostics[] = ['code' => 'module_catalog_invalid', 'message' => $error->getMessage()];
        }

        try {
            $providerInspection = $this->providers->inspect();
            $providers = $providerInspection['effective'] ?? [];
            foreach ($providerInspection['diagnostics'] ?? [] as $item) {
                if (count($diagnostics) >= 50) {
                    break;
                }
                $diagnostics[] = $item;
            }
        } catch (Throwable $error) {
            $diagnostics[] = ['code' => 'provider_inspection_failed', 'message' => $error->getMessage()];
        }

        $roots = SourceRoots::discover($this->project);

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
            'source_roots' => [
                'application' => $this->relativeRoots($roots->application),
                'tests' => $this->relativeRoots($roots->tests),
                'structural' => $this->relativeRoots($roots->structural),
            ],
            'diagnostics' => array_slice($diagnostics, 0, 50),
        ];
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
