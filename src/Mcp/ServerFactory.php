<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp;

use Infocyph\FoundationMcp\Application;
use Infocyph\FoundationMcp\Diagnostics\RuntimeRequirements;
use Infocyph\FoundationMcp\Mcp\Resource\ArchitectureResource;
use Infocyph\FoundationMcp\Mcp\Resource\ComposerResource;
use Infocyph\FoundationMcp\Mcp\Resource\ModuleCatalogResource;
use Infocyph\FoundationMcp\Mcp\Resource\PackageFileResource;
use Infocyph\FoundationMcp\Mcp\Resource\ProjectFileResource;
use Infocyph\FoundationMcp\Mcp\Resource\StandardsResource;
use Infocyph\FoundationMcp\Mcp\Resource\SummaryResource;
use Infocyph\FoundationMcp\Mcp\Resource\SymbolResource;
use Infocyph\FoundationMcp\Mcp\Tool\ChangesTool;
use Infocyph\FoundationMcp\Mcp\Tool\ImpactTool;
use Infocyph\FoundationMcp\Mcp\Tool\InspectTool;
use Infocyph\FoundationMcp\Mcp\Tool\PackagesTool;
use Infocyph\FoundationMcp\Mcp\Tool\ProjectTool;
use Infocyph\FoundationMcp\Mcp\Tool\ReadTool;
use Infocyph\FoundationMcp\Mcp\Tool\SearchTool;
use Infocyph\FoundationMcp\Mcp\Tool\SymbolTool;
use Infocyph\FoundationMcp\Mcp\Tool\ToolServices;
use Infocyph\FoundationMcp\Mcp\Tool\UsagesTool;
use Infocyph\FoundationMcp\Project\Project;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;

final readonly class ServerFactory
{
    public function __construct(
        private Project $project,
        private bool $gitEnabled = true,
    ) {}

    public function create(): Server
    {
        RuntimeRequirements::assertAvailable();

        $services = new ToolServices($this->project, $this->gitEnabled);
        $budget = new OutputBudget();
        $builder = Server::builder()
            ->setServerInfo(
                name: 'foundation-mcp',
                version: Application::VERSION,
                description: 'Read-only development intelligence for Infbyte and Infocyph Foundation applications.',
            )
            ->setPaginationLimit(100)
            ->setInstructions(sprintf(
                'Use Foundation MCP for precise, bounded, read-only local project and Foundation context. Host type: %s.',
                $this->project->hostType->value,
            ));

        $annotations = new ToolAnnotations(readOnlyHint: true, destructiveHint: false, openWorldHint: false);
        foreach ([
            new ProjectTool($services), new SearchTool($services), new ReadTool($services),
            new SymbolTool($services), new UsagesTool($services), new InspectTool($services),
            new PackagesTool($services), new ChangesTool($services), new ImpactTool($services),
        ] as $tool) {
            $handler = static fn(...$arguments): array => $budget->tool($tool->execute(...$arguments));

            $builder->addTool(
                handler: $handler,
                name: $tool::NAME,
                description: $tool::DESCRIPTION,
                annotations: $annotations,
                inputSchema: $tool::INPUT_SCHEMA,
                outputSchema: ['type' => 'object'],
            );
        }

        $builder
            ->addResource([new SummaryResource($services), 'execute'], 'foundation://project/summary', 'project_summary', description: 'Bounded authoritative summary of the current Infbyte/Foundation project.', mimeType: 'application/json')
            ->addResource([new ArchitectureResource($services), 'execute'], 'foundation://project/architecture', 'project_architecture', description: 'Current Foundation runtime, modules, providers, dependencies, and source-root architecture.', mimeType: 'application/json')
            ->addResource([new ComposerResource($services), 'execute'], 'foundation://project/composer', 'project_composer', description: 'Bounded Composer package/dependency and exact locked/installed version state.', mimeType: 'application/json')
            ->addResource([new ModuleCatalogResource($services), 'execute'], 'foundation://project/module-catalog', 'project_module_catalog', description: 'Installed Foundation purpose-first ModuleCatalog definitions and correlated project state.', mimeType: 'application/json')
            ->addResource([new StandardsResource($services), 'execute'], 'foundation://project/standards', 'project_standards', description: 'Bounded local project, Foundation, and PHPForge development standards with source attribution.', mimeType: 'application/json')
            ->addResourceTemplate([new ProjectFileResource($services), 'execute'], 'foundation://project/file/{path}', 'project_file', description: 'Safe bounded read of an approved project file. Encode path separators in the URI variable.', mimeType: 'text/plain')
            ->addResourceTemplate([new PackageFileResource($services), 'execute'], 'foundation://package/{package}/file/{path}', 'package_file', description: 'Safe bounded read of one explicitly addressed installed package file.', mimeType: 'text/plain')
            ->addResourceTemplate([new SymbolResource($services), 'execute'], 'foundation://symbol/{symbol}', 'symbol', description: 'Exact bounded PHP symbol declaration/signature/source information.', mimeType: 'application/json');

        return $builder->build();
    }
}
