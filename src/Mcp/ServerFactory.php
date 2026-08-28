<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp;

use Infocyph\FoundationMcp\Application;
use Infocyph\FoundationMcp\Project\Project;
use Mcp\Server;

final readonly class ServerFactory
{
    public function __construct(
        private Project $project,
    ) {
    }

    public function create(): Server
    {
        return Server::builder()
            ->setServerInfo(
                name: 'foundation-mcp',
                version: Application::VERSION,
                description: 'Read-only development intelligence for Infbyte and Infocyph Foundation applications.',
            )
            ->setInstructions(sprintf(
                'Use Foundation MCP for precise, bounded, read-only local project and Foundation context. Host type: %s.',
                $this->project->hostType->value,
            ))
            ->build();
    }
}
