<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Resource;

use Infocyph\FoundationMcp\Mcp\Tool\ToolServices;

final readonly class ModuleCatalogResource
{
    public function __construct(
        private ToolServices $services,
    ) {}

    public function execute(): string
    {
        return ResourcePayload::json([
            'definitions' => $this->services->modules()->definitions(),
            'modules' => $this->services->modules()->modules(),
        ]);
    }
}
