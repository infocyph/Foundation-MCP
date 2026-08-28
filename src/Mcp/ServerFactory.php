<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp;

use Infocyph\FoundationMcp\Application;
use Mcp\Server;

final class ServerFactory
{
    public function create(): Server
    {
        return Server::builder()
            ->setServerInfo(
                name: 'foundation-mcp',
                version: Application::VERSION,
                description: 'Read-only development intelligence for Infbyte and Infocyph Foundation applications.',
            )
            ->setInstructions('Use Foundation MCP for precise, bounded, read-only local project and Foundation context.')
            ->build();
    }
}
