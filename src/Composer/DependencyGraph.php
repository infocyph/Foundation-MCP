<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Composer;

use InvalidArgumentException;

final readonly class DependencyGraph
{
    /**
     * @param array<string, array<string, string>> $edges
     * @param list<string> $runtimeDirect
     * @param list<string> $devDirect
     */
    public function __construct(
        private array $edges,
        private array $runtimeDirect,
        private array $devDirect,
    ) {}

    /** @return array<string, array<string, string>> */
    public function all(): array
    {
        return $this->edges;
    }

    /**
     * @return list<array{package: string, depth: int, constraint: string}>
     */
    public function dependencies(string $package, int $depth = 1): array
    {
        if ($depth < 1 || $depth > 10) {
            throw new InvalidArgumentException('Dependency depth must be between 1 and 10.');
        }

        if (!isset($this->edges[$package])) {
            return [];
        }

        $results = [];
        $seen = [$package => true];
        $queue = [[$package, 0]];
        $cursor = 0;

        while (isset($queue[$cursor])) {
            [$current, $currentDepth] = $queue[$cursor++];

            if ($currentDepth >= $depth) {
                continue;
            }

            foreach ($this->edges[$current] ?? [] as $dependency => $constraint) {
                $nextDepth = $currentDepth + 1;

                if (!isset($seen[$dependency])) {
                    $seen[$dependency] = true;
                    $results[] = [
                        'package' => $dependency,
                        'depth' => $nextDepth,
                        'constraint' => $constraint,
                    ];
                    $queue[] = [$dependency, $nextDepth];
                }
            }
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    public function dependents(string $package): array
    {
        $dependents = [];

        foreach ($this->edges as $candidate => $requirements) {
            if (isset($requirements[$package])) {
                $dependents[] = $candidate;
            }
        }

        sort($dependents, SORT_STRING);

        return $dependents;
    }

    /** @return list<string> */
    public function devDirect(): array
    {
        return $this->devDirect;
    }

    /** @return list<string> */
    public function runtimeDirect(): array
    {
        return $this->runtimeDirect;
    }
}
