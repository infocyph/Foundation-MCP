<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp;

use Infocyph\FoundationMcp\Diagnostics\Doctor;
use Infocyph\FoundationMcp\Mcp\ServerFactory;
use Mcp\Server\Transport\StdioTransport;
use Throwable;

final class Application
{
    public const VERSION = '0.1.0-dev';

    /**
     * @param list<string> $argv
     */
    public static function run(array $argv): int
    {
        $command = $argv[1] ?? 'serve';

        if (in_array($command, ['-h', '--help', 'help'], true)) {
            self::writeHelp();

            return 0;
        }

        if (in_array($command, ['-V', '--version'], true)) {
            fwrite(STDERR, 'Foundation MCP '.self::VERSION.PHP_EOL);

            return 0;
        }

        return match ($command) {
            'serve' => self::serve(),
            'doctor' => (new Doctor())->run(),
            default => self::unknown($command),
        };
    }

    private static function serve(): int
    {
        try {
            return (new ServerFactory())->create()->run(new StdioTransport());
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Foundation MCP failed to start: '.$exception->getMessage().PHP_EOL);

            return 1;
        }
    }

    private static function unknown(string $command): int
    {
        fwrite(STDERR, sprintf("Unknown command \"%s\".%s", $command, PHP_EOL));
        self::writeHelp();

        return 2;
    }

    private static function writeHelp(): void
    {
        fwrite(STDERR, <<<'TEXT'
Foundation MCP

Usage:
  foundation-mcp [serve]
  foundation-mcp doctor
  foundation-mcp --help
  foundation-mcp --version

The server uses MCP over STDIO. STDOUT is reserved exclusively for protocol output.

TEXT);
    }
}
