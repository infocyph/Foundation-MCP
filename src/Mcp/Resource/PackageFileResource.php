<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Resource;

use Infocyph\FoundationMcp\Mcp\Tool\ToolServices;

final readonly class PackageFileResource
{
    public function __construct(private ToolServices $services) {}

    public function execute(string $package, string $path): string
    {
        return $this->services->reader()->package(rawurldecode($package), rawurldecode($path))['content'];
    }
}
