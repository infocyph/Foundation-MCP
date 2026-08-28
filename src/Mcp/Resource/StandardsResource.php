<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Resource;

use Infocyph\FoundationMcp\Mcp\Tool\ToolServices;
use Throwable;

final readonly class StandardsResource
{
    private const array CONFIGS = ['phpforge.json', '.phpforge.json', 'phpstan.neon', 'phpstan.neon.dist', 'rector.php', 'pint.json', 'phpcs.xml', 'phpcs.xml.dist'];

    private const array DOCUMENTS = ['AGENTS.md', 'CONTRIBUTING.md', 'SECURITY.md', 'README.md'];

    private const int MAX_EXCERPT_BYTES = 16_384;

    private const int MAX_SOURCES = 18;

    public function __construct(private ToolServices $services) {}

    public function execute(): string
    {
        $sources = [];
        foreach ([...self::DOCUMENTS, ...self::CONFIGS] as $path) {
            $this->project($path, $sources);
            if (count($sources) >= self::MAX_SOURCES) {
                break;
            }
        }
        foreach (['infocyph/foundation', 'infocyph/phpforge'] as $package) {
            if (count($sources) >= self::MAX_SOURCES || $this->services->composer()->package($package)?->installPath === null) {
                continue;
            }
            foreach ([...self::DOCUMENTS, ...self::CONFIGS] as $path) {
                $this->package($package, $path, $sources);
                if (count($sources) >= self::MAX_SOURCES) {
                    break;
                }
            }
        }
        usort($sources, static fn(array $left, array $right): int => [$left['owner'], $left['path']] <=> [$right['owner'], $right['path']]);

        return ResourcePayload::json(['sources' => $sources, 'source_count' => count($sources), 'truncated' => count($sources) >= self::MAX_SOURCES]);
    }

    /** @param list<array<string,mixed>> $sources */
    private function package(string $package, string $path, array &$sources): void
    {
        try {
            $sources[] = $this->source($package, $path, $this->services->reader()->package($package, $path, 1, 120));
        } catch (Throwable) {
        }
    }

    /** @param list<array<string,mixed>> $sources */
    private function project(string $path, array &$sources): void
    {
        try {
            $sources[] = $this->source('project', $path, $this->services->reader()->project($path, 1, 120));
        } catch (Throwable) {
        }
    }

    /** @param array<string,mixed> $read @return array<string,mixed> */
    private function source(string $owner, string $path, array $read): array
    {
        $original = (string) ($read['content'] ?? '');
        $excerpt = strlen($original) > self::MAX_EXCERPT_BYTES ? substr($original, 0, self::MAX_EXCERPT_BYTES) . '…' : $original;

        return [
            'owner' => $owner,
            'path' => $path,
            'kind' => in_array($path, self::DOCUMENTS, true) ? 'documentation' : 'configuration',
            'excerpt' => $excerpt,
            'truncated' => (bool) ($read['truncated'] ?? false) || strlen($original) > self::MAX_EXCERPT_BYTES,
        ];
    }
}
