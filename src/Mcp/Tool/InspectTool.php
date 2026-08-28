<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Tool;

use Infocyph\FoundationMcp\Project\SourceRoots;
use InvalidArgumentException;

final readonly class InspectTool
{
    public const string NAME = 'foundation_inspect';
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

    public function __construct(
        private ToolServices $services,
    ) {
    }

    /** @return array<string,mixed> */
    public function execute(string $kind): array
    {
        return match ($kind) {
            'architecture' => $this->services->architecture()->inspect(),
            'modules' => [
                'kind' => 'modules',
                'modules' => $this->services->modules()->modules(),
            ],
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
        $roots = SourceRoots::discover($this->services->project);
        $composer = $this->services->project->composer;

        return [
            'kind' => 'autoload',
            'autoload' => $this->safeAutoload(is_array($composer['autoload'] ?? null) ? $composer['autoload'] : []),
            'autoload_dev' => $this->safeAutoload(is_array($composer['autoload-dev'] ?? null) ? $composer['autoload-dev'] : []),
            'source_roots' => [
                'application' => $this->relativeRoots($roots->application),
                'tests' => $this->relativeRoots($roots->tests),
                'structural' => $this->relativeRoots($roots->structural),
            ],
        ];
    }

    private function safeAutoload(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if (++$count > 200) {
                    $result['__truncated__'] = true;
                    break;
                }
                $result[$key] = $this->safeAutoload($item);
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

        return strlen($value) > 2_048 ? substr($value, 0, 2_048).'…' : $value;
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
}
