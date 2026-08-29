<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp;

use Infocyph\FoundationMcp\Cli\Arguments;
use Infocyph\FoundationMcp\Diagnostics\Doctor;
use Infocyph\FoundationMcp\Mcp\ServerFactory;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Project\ProjectLocator;
use Mcp\Server\Transport\StdioTransport;
use Throwable;

final class Application
{
    public const string VERSION = '0.1.0-dev';

    /** @param list<string> $argv */
    public static function run(array $argv): int
    {
        try {
            $arguments = Arguments::parse($argv);
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);
            self::writeHelp();

            return 2;
        }

        if ($arguments->help || $arguments->command === 'help') {
            self::writeHelp();

            return 0;
        }

        if ($arguments->version) {
            fwrite(STDERR, 'Foundation MCP ' . self::VERSION . PHP_EOL);

            return 0;
        }

        return match ($arguments->command) {
            'serve' => self::serve($arguments),
            'doctor' => new Doctor($arguments->root, $arguments->gitEnabled)->run(),
            default => self::unknown($arguments->command),
        };
    }

    private static function project(Arguments $arguments): Project
    {
        $root = new ProjectLocator()->locate($arguments->root);

        return new ProjectDetector()->detect($root);
    }

    private static function serve(Arguments $arguments): int
    {
        try {
            $project = self::project($arguments);
            if (!$project->supported()) {
                fwrite(STDERR, 'Foundation MCP does not support the resolved project.' . PHP_EOL);

                return 1;
            }

            return new ServerFactory($project, $arguments->gitEnabled)->create()->run(new StdioTransport());
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Foundation MCP failed to start: ' . $exception->getMessage() . PHP_EOL);
            if ($arguments->verbose) {
                fwrite(STDERR, $exception::class . PHP_EOL);
            }

            return 1;
        }
    }

    private static function unknown(string $command): int
    {
        fwrite(STDERR, sprintf('Unknown command "%s".%s', $command, PHP_EOL));
        self::writeHelp();

        return 2;
    }

    private static function writeHelp(): void
    {
        fwrite(STDERR, <<<'TEXT'
Foundation MCP

Usage:
  foundation-mcp [serve] [--root=<path>] [--verbose] [--no-git]
  foundation-mcp doctor [--root=<path>] [--verbose] [--no-git]
  foundation-mcp --help
  foundation-mcp --version

The server uses MCP over STDIO. STDOUT is reserved exclusively for protocol output.

TEXT);
    }
}
