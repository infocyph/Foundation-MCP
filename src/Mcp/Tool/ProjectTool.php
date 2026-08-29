<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Tool;

use Infocyph\FoundationMcp\Composer\InstalledPackage;
use Infocyph\FoundationMcp\Diagnostics\RuntimeRequirements;
use Infocyph\FoundationMcp\Project\SourceRoots;
use Throwable;

final readonly class ProjectTool
{
    public const string DESCRIPTION = 'Return the authoritative bounded summary of the current Infbyte/Foundation host, Composer/Foundation state, analysis readiness, source roots, modules, and lightweight Git state.';

    public const array INPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ];

    public const string NAME = 'foundation_project';

    public function __construct(
        private ToolServices $services,
    ) {}

    /** @return array<string,mixed> */
    public function execute(): array
    {
        $project = $this->services->project;
        $composer = $this->services->composer();
        $foundation = $composer->foundation();
        $modules = [];
        $moduleDiagnostics = [];
        $sourceRoots = ['application' => [], 'tests' => [], 'structural' => []];
        $sourceRootDiagnostics = [];

        try {
            $modules = $this->services->modules()->modules();
        } catch (Throwable $error) {
            $moduleDiagnostics[] = [
                'code' => 'module_catalog_invalid',
                'message' => $error->getMessage(),
            ];
        }

        try {
            $roots = SourceRoots::discover($project);
            $sourceRoots = [
                'application' => $this->relativeRoots($roots->application),
                'tests' => $this->relativeRoots($roots->tests),
                'structural' => $this->relativeRoots($roots->structural),
            ];
        } catch (Throwable $error) {
            $sourceRootDiagnostics[] = [
                'code' => 'source_root_inspection_failed',
                'message' => $error->getMessage(),
            ];
        }

        return [
            'host_type' => $project->hostType->value,
            'php' => [
                'version' => PHP_VERSION,
                'constraint' => $composer->phpConstraint(),
            ],
            'foundation' => $foundation === null ? null : $this->package($foundation),
            'composer' => [
                'lock_present' => $composer->lockPresent(),
                'installed_metadata_present' => $composer->installedMetadataPresent(),
                'runtime_direct' => $composer->graph()->runtimeDirect(),
                'dev_direct' => $composer->graph()->devDirect(),
                'platform_requirements' => $composer->platformRequirements(),
                'diagnostics' => $composer->diagnostics(),
            ],
            'analysis' => $this->analysisReadiness(),
            'source_roots' => $sourceRoots,
            'source_root_diagnostics' => $sourceRootDiagnostics,
            'git' => $this->gitSummary(),
            'modules' => $modules,
            'module_diagnostics' => $moduleDiagnostics,
        ];
    }

    /** @return array{ready:bool,phpforge:?string,parser:bool,diagnostics:list<array{code:string,message:string}>} */
    private function analysisReadiness(): array
    {
        $diagnostics = [];
        $phpForge = RuntimeRequirements::phpForgeCheck();
        $parser = RuntimeRequirements::parserCheck();

        if (!$phpForge['ok']) {
            $diagnostics[] = ['code' => 'phpforge_unavailable', 'message' => $phpForge['detail']];
        }
        if (!$parser['ok']) {
            $diagnostics[] = [
                'code' => str_starts_with($parser['detail'], 'Parser compatibility failure:')
                    ? 'analysis_backend_incompatible'
                    : 'analysis_backend_unavailable',
                'message' => $parser['detail'],
            ];
        }

        return [
            'ready' => $phpForge['ok'] && $parser['ok'],
            'phpforge' => $phpForge['ok'] ? $phpForge['detail'] : null,
            'parser' => $parser['ok'],
            'diagnostics' => $diagnostics,
        ];
    }

    /** @return array{available:bool,head:?string,branch:?string,detached:bool,dirty:bool,diagnostics:list<array{code:string,message:string}>} */
    private function gitSummary(): array
    {
        $git = $this->services->git();
        if (!$git->available()) {
            return [
                'available' => false,
                'head' => null,
                'branch' => null,
                'detached' => false,
                'dirty' => false,
                'diagnostics' => [['code' => 'git_unavailable', 'message' => 'Git is unavailable for this project.']],
            ];
        }

        try {
            $status = $git->status();
            $head = $git->head();
            $branch = $git->branch();
            $headValue = $head['exit'] === 0 ? trim($head['stdout']) : null;
            $branchValue = $branch['exit'] === 0 ? trim($branch['stdout']) : null;

            return [
                'available' => true,
                'head' => $headValue,
                'branch' => $branchValue,
                'detached' => $headValue !== null && $branchValue === null,
                'dirty' => $status['stdout'] !== '',
                'diagnostics' => [],
            ];
        } catch (Throwable $error) {
            return [
                'available' => false,
                'head' => null,
                'branch' => null,
                'detached' => false,
                'dirty' => false,
                'diagnostics' => [['code' => 'git_unavailable', 'message' => $error->getMessage()]],
            ];
        }
    }

    /** @return array<string,mixed> */
    private function package(InstalledPackage $package): array
    {
        return [
            'name' => $package->name,
            'declared_constraint' => $package->declaredConstraint,
            'declared_scope' => $package->declaredScope,
            'locked_version' => $package->lockedVersion,
            'installed_version' => $package->installedVersion,
            'locked_reference' => $package->lockedReference,
            'installed_reference' => $package->installedReference,
            'state' => $package->state(),
            'source_available' => $package->installPath !== null,
        ];
    }

    /** @param list<string> $roots @return list<string> */
    private function relativeRoots(array $roots): array
    {
        $root = str_replace('\\', '/', rtrim($this->services->project->root, '/\\'));
        $result = [];

        foreach ($roots as $path) {
            $path = str_replace('\\', '/', rtrim($path, '/\\'));
            $result[] = $path === $root ? '.' : substr($path, strlen($root) + 1);
        }

        sort($result, SORT_STRING);

        return array_values(array_unique($result));
    }
}
