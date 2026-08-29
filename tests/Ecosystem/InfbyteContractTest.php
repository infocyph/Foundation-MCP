<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Diagnostics\Doctor;
use Infocyph\FoundationMcp\Foundation\ModuleCatalogReader;
use Infocyph\FoundationMcp\Mcp\ServerFactory;
use Infocyph\FoundationMcp\Project\ProjectDetector;

it('matches the current real Infbyte and installed Foundation ecosystem contract', function (): void {
    $root = getenv('FOUNDATION_MCP_ECOSYSTEM_ROOT');
    if (!is_string($root) || $root === '') {
        expect(true)->toBeTrue();

        return;
    }

    $project = (new ProjectDetector())->detect($root);
    expect($project->supported())->toBeTrue();

    $composer = new ComposerInspector($project);
    $foundation = $composer->foundation();

    expect($foundation)->not->toBeNull()
        ->and($foundation->declaredConstraint)->not->toBeNull()
        ->and($foundation->lockedVersion)->not->toBeNull()
        ->and($foundation->installedVersion)->not->toBeNull()
        ->and($foundation->state())->toBe('matched');

    $modules = (new ModuleCatalogReader($project, $composer))->definitions();
    expect($modules)->not->toBeEmpty()
        ->and(array_keys($modules))->toContain('database', 'messaging', 'validation');

    $doctorChecks = (new Doctor($root, gitEnabled: false))->checks();
    expect(array_filter($doctorChecks, static fn (array $check): bool => !$check['ok']))->toBe([]);

    expect((new ServerFactory($project, gitEnabled: false))->create())->not->toBeNull();
});
