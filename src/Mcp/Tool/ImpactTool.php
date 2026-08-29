<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Tool;

final readonly class ImpactTool
{
    public const string DESCRIPTION = 'Return a deterministic bounded impact graph for a symbol, file, package, Foundation module, route, config key/path, or the current workspace changes with explicit evidence confidence.';

    public const array INPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'kind' => [
                'type' => 'string',
                'enum' => ['symbol', 'file', 'package', 'module', 'route', 'config', 'changes'],
            ],
            'target' => ['type' => ['string', 'null'], 'maxLength' => 4096],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100],
        ],
        'required' => ['kind'],
        'additionalProperties' => false,
    ];

    public const string NAME = 'foundation_impact';

    public function __construct(
        private ToolServices $services,
    ) {}

    /** @return array<string,mixed> */
    public function execute(string $kind, ?string $target = null, int $limit = 100): array
    {
        return $this->services->impact()->analyze($kind, $target, $limit);
    }
}
