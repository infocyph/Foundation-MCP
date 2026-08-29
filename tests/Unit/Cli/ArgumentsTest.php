<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Cli\Arguments;
use InvalidArgumentException;

it('parses an explicit root path without consuming later options', function (): void {
    $arguments = Arguments::parse(['foundation-mcp', '--root', '/tmp/project', '--verbose']);

    expect($arguments->root)->toBe('/tmp/project')
        ->and($arguments->verbose)->toBeTrue();
});

it('rejects a missing root value before another option', function (): void {
    expect(fn(): Arguments => Arguments::parse(['foundation-mcp', '--root', '--verbose']))
        ->toThrow(InvalidArgumentException::class, '--root requires a path.');
});

it('rejects an option-looking inline root value', function (): void {
    expect(fn(): Arguments => Arguments::parse(['foundation-mcp', '--root=--verbose']))
        ->toThrow(InvalidArgumentException::class, '--root requires a path.');
});
