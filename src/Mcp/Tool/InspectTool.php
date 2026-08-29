<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Tool;

use Infocyph\FoundationMcp\Project\SourceRoots;
use InvalidArgumentException;
use Throwable;

final readonly class InspectTool
{
    private const int MAX_AUTOLOAD_DEPTH = 16;

    private const int MAX_AUTOLOAD_ITEMS = 1_024;

    private const int MAX_STRING_BYTES = 2_048;

    public const string DESCRIPTION = 'Inspect one Foundation structural concern: architecture, modules, routes, commands, providers, config, workers, schedules, runtime/bootstrap, or Composer autoload roots.';

    public const array INPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'kind' => [
                'type' => 'string',
                'enum' => ['architecture', 'modules', 'routes', 'commands', 'providers', 'config', 'workers', 'schedules', 'runtime', 'autoload'],
            ],
        ],
        'required' => ['kind'],
        'additionalProperties' => false,
    ];

    public const string NAME = 'foundation_inspect';

    public function __construct(
        private ToolServices $services,
    ) {}

    /** @return array<string,mixed> */
    public function execute(string $kind): array
    {
        return match ($kind) {
            'architecture' => $this->services->architecture()->inspect(),
            'modules' => $this->modules(),
            'routes' => ['kind' => 'routes', ...$this->services->routes()->inspect()],
            'commands' => ['kind' => 'commands', ...$this->services->commands()->inspect()],
            'providers' => ['kind' => 'providers', ...$this->services->providers()->inspect()],
            'config' => ['kind' => 'config', ...$this->services->config()->inspect()],
            'workers' => ['kind' => 'workers', ...$this->services->workers()->inspect()],
            'schedules' => ['kind' => 'schedules', ...$this->services->schedules()->inspect()],
            'runtime' => ['kind' => 'runtime', ...$this->services->runtime()->inspect()],
            'autoload' => $this->autoload(),
            default => throw new InvalidArgumentException('Unsupported inspection kind.'),
        };
    }

    /** @return array<string,mixed> */
    private function autoload(): array
    {
        $composer = $this->services->project->composer;
        $autoload = is_array($composer['autoload'] ?? null) ? $composer['autoload'] : [];
        $autoloadDev = is_array($composer['autoload-dev'] ?? null) ? $composer['autoload-dev'] : [];
        [$safeAutoload, $autoloadTruncated] = $this->safeAutoload($autoload);
        [$safeAutoloadDev, $autoloadDevTruncated] = $this->safeAutoload($autoloadDev);
        $sourceRoots = ['application' => [], 'tests' => [], 'structural' => []];
        $diagnostics = [];

        try {
            $roots = SourceRoots::discover($this->services->project);
            $sourceRoots = [
                'application' => $this->relativeRoots($roots->application),
                'tests' => $this->relativeRoots($roots->tests),
                'structural' => $this->relativeRoots($roots->structural),
            ];
        } catch (Throwable $error) {
            $diagnostics[] = [
                'code' => 'source_root_inspection_failed',
                'message' => $error->getMessage(),
            ];
        }

        return [
            'kind' => 'autoload',
            'autoload' => $safeAutoload,
            'autoload_truncated' => $autoloadTruncated,
            'autoload_dev' => $safeAutoloadDev,
            'autoload_dev_truncated' => $autoloadDevTruncated,
            'source_roots' => $sourceRoots,
            'diagnostics' => $diagnostics,
        ];
    }

    /** @return array<string,mixed> */
    private function modules(): array
    {
        try {
            return [
                'kind' => 'modules',
                'modules' => $this->services->modules()->modules(),
                'diagnostics' => [],
            ];
        } catch (Throwable $error) {
            return [
                'kind' => 'modules',
                'modules' => [],
                'diagnostics' => [[
                    'code' => 'module_catalog_invalid',
                    'message' => $error->getMessage(),
                ]],
            ];
        }
    }

    /** @param list<string> $roots @return list<string> */
    private function relativeRoots(array $roots): array
    {
        $projectRoot = str_replace('\\', '/', rtrim($this->services->project->root, '/\\'));
        $result = [];

        foreach ($roots as $root) {
            $root = str_replace('\\', '/', rtrim($root, '/\\'));
            $result[] = $root === $projectRoot ? '.' : substr($root, strlen($projectRoot) + 1);
        }

        sort($result, SORT_STRING);

        return array_values(array_unique($result));
    }

    /** @return array{0:mixed,1:bool} */
    private function safeAutoload(mixed $value): array
    {
        $remaining = self::MAX_AUTOLOAD_ITEMS;
        $truncated = false;
        $safe = $this->safeAutoloadValue($value, $remaining, $truncated);

        return [$safe, $truncated];
    }

    private function safeAutoloadValue(mixed $value, int &$remaining, bool &$truncated, int $depth = 0): mixed
    {
        if ($depth >= self::MAX_AUTOLOAD_DEPTH) {
            if (is_array($value)) {
                $truncated = true;

                return '[TRUNCATED]';
            }

            return $value;
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if ($remaining <= 0) {
                    $truncated = true;
                    $result['__truncated__'] = true;

                    break;
                }
                --$remaining;
                $result[$key] = $this->safeAutoloadValue($item, $remaining, $truncated, $depth + 1);
            }

            return $result;
        }
        if (!is_string($value)) {
            return $value;
        }

        $path = str_replace('\\', '/', $value);
        if (str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/^[A-Za-z]:\//', $path) === 1 || in_array('..', explode('/', $path), true)) {
            return '[DENIED_PATH]';
        }
        if (strlen($value) <= self::MAX_STRING_BYTES) {
            return $value;
        }

        $truncated = true;

        return $this->truncateUtf8($value, self::MAX_STRING_BYTES) . '…';
    }

    private function truncateUtf8(string $value, int $bytes): string
    {
        $value = substr($value, 0, $bytes);
        while ($value !== '' && preg_match('//u', $value) !== 1) {
            $value = substr($value, 0, -1);
        }

        return $value;
    }
}
