<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Tool;

use InvalidArgumentException;

final readonly class ReadTool
{
    public const string DESCRIPTION = 'Read a bounded, redacted line range from an approved project file, installed Foundation file, or one explicitly selected installed package.';

    public const array INPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4096],
            'scope' => [
                'type' => 'string',
                'enum' => ['project', 'foundation', 'package'],
                'default' => 'project',
            ],
            'package' => ['type' => ['string', 'null']],
            'start_line' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            'end_line' => ['type' => ['integer', 'null'], 'minimum' => 1],
        ],
        'required' => ['path'],
        'additionalProperties' => false,
    ];

    public const string NAME = 'foundation_read';

    public function __construct(
        private ToolServices $services,
    ) {}

    /** @return array<string,mixed> */
    public function execute(
        string $path,
        string $scope = 'project',
        ?string $package = null,
        int $start_line = 1,
        ?int $end_line = null,
    ): array {
        return match ($scope) {
            'project' => $this->services->reader()->project($path, $start_line, $end_line),
            'foundation' => $this->services->reader()->package('infocyph/foundation', $path, $start_line, $end_line),
            'package' => $this->services->reader()->package(
                $this->requiredPackage($package),
                $path,
                $start_line,
                $end_line,
            ),
            default => throw new InvalidArgumentException('Unsupported read scope.'),
        };
    }

    private function requiredPackage(?string $package): string
    {
        $package = trim((string) $package);
        if ($package === '') {
            throw new InvalidArgumentException('Package read scope requires an explicit package name.');
        }

        return $package;
    }
}
