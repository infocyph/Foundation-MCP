<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Resource;

use Infocyph\FoundationMcp\Mcp\Tool\ProjectTool;
use Infocyph\FoundationMcp\Mcp\Tool\ToolServices;

final readonly class SummaryResource
{
    public function __construct(
        private ToolServices $services,
    ) {
    }

    public function execute(): string
    {
        return ResourcePayload::json((new ProjectTool($this->services))->execute());
    }
}
