<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Resource;

use JsonException;
use RuntimeException;

final class ResourcePayload
{
    private const int MAX_JSON_BYTES = 524_288;

    /** @param array<string,mixed> $value */
    public static function json(array $value): string
    {
        try {
            $encoded = json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            );
        } catch (JsonException $error) {
            throw new RuntimeException('Unable to encode MCP resource payload.', 0, $error);
        }

        if (strlen($encoded) > self::MAX_JSON_BYTES) {
            throw new RuntimeException('MCP resource payload exceeds the 512 KiB limit.');
        }

        return $encoded;
    }
}
