<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp;

use Infocyph\FoundationMcp\Application;
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
    ) {
    }

    public function create(): Server
    {
        $services = new ToolServices($this->project);
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

        $annotations = new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            openWorldHint: false,
        );

        foreach ([
            new ProjectTool($services),
            new SearchTool($services),
            new ReadTool($services),
            new SymbolTool($services),
            new UsagesTool($services),
            new InspectTool($services),
            new PackagesTool($services),
            new ChangesTool($services),
            new ImpactTool($services),
        ] as $tool) {
            $builder->addTool(
                handler: [$tool, 'execute'],
                name: $tool::NAME,
                description: $tool::DESCRIPTION,
                annotations: $annotations,
                inputSchema: $tool::INPUT_SCHEMA,
                outputSchema: ['type' => 'object'],
            );
        }

        return $builder->build();
    }
}
