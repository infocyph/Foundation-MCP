<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Cli;

use InvalidArgumentException;

final readonly class Arguments
{
    public function __construct(
        public string $command,
        public ?string $root,
        public bool $verbose,
        public bool $gitEnabled,
        public bool $help,
        public bool $version,
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public static function parse(array $argv): self
    {
        $command = null;
        $root = null;
        $verbose = false;
        $gitEnabled = true;
        $help = false;
        $version = false;

        for ($index = 1, $count = count($argv); $index < $count; ++$index) {
            $argument = $argv[$index];

            if ($argument === '-h' || $argument === '--help') {
                $help = true;

                continue;
            }

            if ($argument === '-V' || $argument === '--version') {
                $version = true;

                continue;
            }

            if ($argument === '--verbose') {
                $verbose = true;

                continue;
            }

            if ($argument === '--no-git') {
                $gitEnabled = false;

                continue;
            }

            if ($argument === '--root') {
                $root = $argv[++$index] ?? null;

                if (!is_string($root) || $root === '') {
                    throw new InvalidArgumentException('--root requires a path.');
                }

                continue;
            }

            if (str_starts_with($argument, '--root=')) {
                $root = substr($argument, 7);

                if ($root === '') {
                    throw new InvalidArgumentException('--root requires a path.');
                }

                continue;
            }

            if (str_starts_with($argument, '-')) {
                throw new InvalidArgumentException('Unknown option "'.$argument.'".');
            }

            if ($command !== null) {
                throw new InvalidArgumentException('Unexpected argument "'.$argument.'".');
            }

            $command = $argument;
        }

        return new self(
            command: $command ?? 'serve',
            root: $root,
            verbose: $verbose,
            gitEnabled: $gitEnabled,
            help: $help,
            version: $version,
        );
    }
}
