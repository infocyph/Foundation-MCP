<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Mcp\OutputBudget;

it('enforces a global serialized tool response budget', function (): void {
    $budget = new OutputBudget();

    expect($budget->tool(['ok' => true, 'items' => range(1, 100)]))->toMatchArray(['ok' => true])
        ->and(fn () => $budget->tool(['payload' => str_repeat('x', 1_048_576)]))
        ->toThrow(RuntimeException::class, 'MCP tool payload exceeds the 1 MiB limit.');
});
