<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Resource;

use Infocyph\FoundationMcp\Mcp\Tool\PackagesTool;
use Infocyph\FoundationMcp\Mcp\Tool\ToolServices;

final readonly class ComposerResource
{
    public function __construct(
        private ToolServices $services,
    ) {
    }

    public function execute(): string
    {
        return ResourcePayload::json((new PackagesTool($this->services))->execute(limit: 100));
    }
}
