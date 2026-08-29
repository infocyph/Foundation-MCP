<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Project\HostType;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Project\ProjectLocator;
use Infocyph\FoundationMcp\Project\SourceRoots;
use Infocyph\FoundationMcp\Tests\Support\TempProject;

it('detects renamed canonical Infbyte hosts without relying on composer package name', function (): void {
    $root = TempProject::create(
        composer: [
            'name' => 'acme/product',
            'require' => ['infocyph/foundation' => '^2.1'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
        ],
        directories: ['app', 'bootstrap', 'config', 'routes', 'tests'],
        files: [
            'infbyte' => "#!/usr/bin/env php\n",
            'bootstrap/app.php' => "<?php\nuse Infocyph\\Foundation\\Foundation;\nreturn Foundation::web([]);\n",
        ],
    );

    try {
        $project = (new ProjectDetector())->detect($root);
        $roots = SourceRoots::discover($project);

        expect($project->hostType)->toBe(HostType::Infbyte)
            ->and($project->evidence['foundation_constraint'])->toBe('^2.1')
            ->and($roots->application)->toContain(realpath($root.'/app'))
            ->and($roots->tests)->toContain(realpath($root.'/tests'));
    } finally {
        TempProject::remove($root);
    }
});

it('detects custom Foundation hosts and unsupported composer projects', function (): void {
    $custom = TempProject::create([
        'name' => 'acme/custom',
        'require' => ['infocyph/foundation' => '^2.1'],
    ]);
    $unsupported = TempProject::create([
        'name' => 'acme/plain',
        'require' => ['php' => '^8.4'],
    ]);

    try {
        $detector = new ProjectDetector();

        expect($detector->detect($custom)->hostType)->toBe(HostType::FoundationCustom)
            ->and($detector->detect($unsupported)->hostType)->toBe(HostType::Unsupported);
    } finally {
        TempProject::remove($custom);
        TempProject::remove($unsupported);
    }
});

it('walks upward by default but treats explicit root as authoritative', function (): void {
    $host = TempProject::create(
        ['require' => ['infocyph/foundation' => '^2.1']],
        directories: ['nested/deeper'],
    );
    $explicit = TempProject::create(['require' => ['infocyph/foundation' => '^2.1']]);

    try {
        $locator = new ProjectLocator();

        expect($locator->locate(null, $host.'/nested/deeper'))->toBe(realpath($host))
            ->and($locator->locate($explicit, $host.'/nested/deeper'))->toBe(realpath($explicit));
    } finally {
        TempProject::remove($host);
        TempProject::remove($explicit);
    }
});
