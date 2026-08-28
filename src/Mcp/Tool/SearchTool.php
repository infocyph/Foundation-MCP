<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Tool;

final readonly class SearchTool
{
    public const string DESCRIPTION = 'Search project, tests, installed Foundation, one explicit package, routes, config, bootstrap, or docs by symbol, path, or bounded text with deterministic ranking.';

    public const array INPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 512],
            'scope' => [
                'type' => 'string',
                'enum' => ['project', 'tests', 'foundation', 'packages', 'routes', 'config', 'bootstrap', 'docs', 'all'],
                'default' => 'project',
            ],
            'kind' => [
                'type' => 'string',
                'enum' => ['auto', 'symbol', 'path', 'text'],
                'default' => 'auto',
            ],
            'package' => ['type' => ['string', 'null']],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
        ],
        'required' => ['query'],
        'additionalProperties' => false,
    ];

    public const string NAME = 'foundation_search';

    public function __construct(
        private ToolServices $services,
    ) {}

    /** @return array<string,mixed> */
    public function execute(
        string $query,
        string $scope = 'project',
        string $kind = 'auto',
        ?string $package = null,
        int $limit = 20,
    ): array {
        $results = $this->services->search()->search($query, $scope, $kind, $package, $limit);

        return [
            'query' => $query,
            'scope' => $scope,
            'kind' => $kind,
            'package' => $package,
            'count' => count($results),
            'results' => $results,
        ];
    }
}
