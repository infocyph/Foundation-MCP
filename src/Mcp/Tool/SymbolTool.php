<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Tool;

final readonly class SymbolTool
{
    public const string DESCRIPTION = 'Resolve a PHP symbol to its exact declaration, signature, source, ownership, and structural relationships in the project or one explicit installed package.';

    public const array INPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'symbol' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024],
            'package' => ['type' => ['string', 'null']],
        ],
        'required' => ['symbol'],
        'additionalProperties' => false,
    ];

    public const string NAME = 'foundation_symbol';

    public function __construct(
        private ToolServices $services,
    ) {}

    /** @return array<string,mixed> */
    public function execute(string $symbol, ?string $package = null): array
    {
        $matches = $this->services->symbols()->find($symbol, $package);
        $diagnostics = array_slice($this->services->symbols()->diagnostics($package), 0, 50);

        if ($matches === []) {
            return [
                'status' => 'not_found',
                'query' => $symbol,
                'package' => $package,
                'declaration' => null,
                'candidates' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        if (count($matches) > 1) {
            return [
                'status' => 'ambiguous',
                'query' => $symbol,
                'package' => $package,
                'declaration' => null,
                'candidates' => array_slice($matches, 0, 20),
                'diagnostics' => $diagnostics,
            ];
        }

        $declaration = $matches[0];

        return [
            'status' => 'resolved',
            'query' => $symbol,
            'package' => $package,
            'declaration' => $declaration,
            'relationships' => [
                'extends' => $declaration['extends'],
                'implements' => $declaration['implements'],
                'traits' => $declaration['traits'],
                'attributes' => $declaration['attributes'],
            ],
            'candidates' => [],
            'diagnostics' => $diagnostics,
        ];
    }
}
