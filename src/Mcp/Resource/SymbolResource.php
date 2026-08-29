<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Resource;

use Infocyph\FoundationMcp\Mcp\Tool\SymbolTool;
use Infocyph\FoundationMcp\Mcp\Tool\ToolServices;

final readonly class SymbolResource
{
    public function __construct(private ToolServices $services) {}

    public function execute(string $symbol): string
    {
        return ResourcePayload::json(new SymbolTool($this->services)->execute(rawurldecode($symbol)));
    }
}
