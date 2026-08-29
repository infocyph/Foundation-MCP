<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Tool;

final readonly class UsagesTool
{
    public const string DESCRIPTION = 'Return deterministic bounded references to a PHP symbol with source symbol, relationship, source location, and exact/resolved/lexical/dynamic confidence.';

    public const array INPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'symbol' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024],
            'package' => ['type' => ['string', 'null']],
            'relationships' => [
                'type' => ['array', 'null'],
                'items' => [
                    'type' => 'string',
                    'enum' => ['import', 'new', 'extends', 'implements', 'trait-use', 'attribute', 'type', 'call', 'class_constant', 'property'],
                ],
                'maxItems' => 10,
                'uniqueItems' => true,
            ],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100],
        ],
        'required' => ['symbol'],
        'additionalProperties' => false,
    ];

    public const string NAME = 'foundation_usages';

    private const int MAX_DIAGNOSTICS = 50;

    public function __construct(
        private ToolServices $services,
    ) {}

    /** @param list<string>|null $relationships @return array<string,mixed> */
    public function execute(
        string $symbol,
        ?string $package = null,
        ?array $relationships = null,
        int $limit = 100,
    ): array {
        $usages = $this->services->references()->usages($symbol, $package, $relationships, $limit);
        $allDiagnostics = $this->services->references()->diagnostics($package);

        return [
            'symbol' => $symbol,
            'package' => $package,
            'relationships' => $relationships,
            'count' => count($usages),
            'usages' => $usages,
            'diagnostics' => array_slice($allDiagnostics, 0, self::MAX_DIAGNOSTICS),
            'diagnostics_truncated' => count($allDiagnostics) > self::MAX_DIAGNOSTICS,
        ];
    }
}
