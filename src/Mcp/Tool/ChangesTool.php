<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Tool;

final readonly class ChangesTool
{
    public const string NAME = 'foundation_changes';
    public const string DESCRIPTION = 'Return compact read-only Git workspace intelligence, changed PHP declarations/references, structural areas, related tests, and Composer dependency changes without returning full diffs.';
    public const array INPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ];

    public function __construct(
        private ToolServices $services,
    ) {
    }

    /** @return array<string,mixed> */
    public function execute(): array
    {
        $workspace = $this->services->workspace()->inspect();
        $dependencies = $workspace['composer_changed']
            ? $this->services->dependencies()->inspect()
            : null;

        return [
            'workspace' => $workspace,
            'dependencies' => $dependencies,
        ];
    }
}
