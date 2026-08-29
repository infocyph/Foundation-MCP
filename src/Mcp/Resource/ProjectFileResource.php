<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Resource;

use Infocyph\FoundationMcp\Mcp\Tool\ToolServices;

final readonly class ProjectFileResource
{
    public function __construct(private ToolServices $services) {}

    public function execute(string $path): string
    {
        return $this->services->reader()->project(rawurldecode($path))['content'];
    }
}
