<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Tool;

use Composer\InstalledVersions;
use Infocyph\FoundationMcp\Composer\InstalledPackage;
use Infocyph\FoundationMcp\Project\SourceRoots;
use PhpParser\ParserFactory;
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
        $roots = SourceRoots::discover($project);
        $modules = [];
        $moduleDiagnostics = [];

        try {
            $modules = $this->services->modules()->modules();
        } catch (Throwable $error) {
            $moduleDiagnostics[] = [
                'code' => 'module_catalog_invalid',
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
                'diagnostics' => array_slice($composer->diagnostics(), 0, 100),
            ],
            'analysis' => $this->analysisReadiness(),
            'source_roots' => [
                'application' => $this->relativeRoots($roots->application),
                'tests' => $this->relativeRoots($roots->tests),
                'structural' => $this->relativeRoots($roots->structural),
            ],
            'git' => $this->gitSummary(),
            'modules' => $modules,
            'module_diagnostics' => $moduleDiagnostics,
        ];
    }

    /** @return array{ready:bool,phpforge:?string,parser:bool,diagnostics:list<array{code:string,message:string}>} */
    private function analysisReadiness(): array
    {
        $diagnostics = [];
        $phpforge = null;

        try {
            if (InstalledVersions::isInstalled('infocyph/phpforge')) {
                $phpforge = InstalledVersions::getPrettyVersion('infocyph/phpforge')
                    ?? InstalledVersions::getVersion('infocyph/phpforge')
                    ?? 'installed';
            } else {
                $diagnostics[] = ['code' => 'phpforge_unavailable', 'message' => 'PHPForge is not installed.'];
            }
        } catch (Throwable $error) {
            $diagnostics[] = ['code' => 'phpforge_unavailable', 'message' => $error->getMessage()];
        }

        $parser = false;
        if (class_exists(ParserFactory::class)) {
            try {
                $nodes = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php final class FoundationMcpToolProbe {}');
                $parser = is_array($nodes) && $nodes !== [];
            } catch (Throwable $error) {
                $diagnostics[] = ['code' => 'analysis_backend_incompatible', 'message' => $error->getMessage()];
            }
        }
        if (!$parser && !array_any($diagnostics, static fn(array $item): bool => $item['code'] === 'analysis_backend_incompatible')) {
            $diagnostics[] = ['code' => 'analysis_backend_unavailable', 'message' => 'PHP parser capability is unavailable.'];
        }

        return [
            'ready' => $phpforge !== null && $parser,
            'phpforge' => $phpforge,
            'parser' => $parser,
            'diagnostics' => array_slice($diagnostics, 0, 20),
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
