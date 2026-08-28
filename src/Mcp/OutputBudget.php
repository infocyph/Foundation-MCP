<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp;

use JsonException;
use RuntimeException;

final class OutputBudget
{
    private const int MAX_TOOL_BYTES = 1_048_576;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function tool(array $payload): array
    {
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new RuntimeException('Unable to encode MCP tool payload.', 0, $error);
        }

        if (strlen($encoded) > self::MAX_TOOL_BYTES) {
            throw new RuntimeException('MCP tool payload exceeds the 1 MiB limit.');
        }

        return $payload;
    }
}
