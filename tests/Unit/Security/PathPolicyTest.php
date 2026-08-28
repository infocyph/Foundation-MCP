<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Security\PathPolicy;
use Infocyph\FoundationMcp\Tests\Support\TempProject;

it('allows only approved project and package roots', function (): void {
    $project = TempProject::create(['require' => ['infocyph/foundation' => '^2.1']]);
    $package = TempProject::create(['name' => 'vendor/package']);

    try {
        $policy = new PathPolicy($project, ['vendor/package' => $package]);

        expect($policy->projectFile('composer.json'))->toBe(realpath($project.'/composer.json'));
        expect($policy->packageFile('vendor/package', 'composer.json'))->toBe(realpath($package.'/composer.json'));
        expect(fn () => $policy->packageFile('other/package', 'composer.json'))
            ->toThrow(RuntimeException::class);
    } finally {
        TempProject::remove($project);
        TempProject::remove($package);
    }
});

it('rejects traversal absolute paths and symlink escapes', function (): void {
    $project = TempProject::create(['require' => ['infocyph/foundation' => '^2.1']]);
    $outside = tempnam(sys_get_temp_dir(), 'foundation-mcp-outside-');

    try {
        $policy = new PathPolicy($project);

        expect(fn () => $policy->projectFile('../composer.json'))->toThrow(RuntimeException::class);
        expect(fn () => $policy->projectFile('/etc/passwd'))->toThrow(RuntimeException::class);

        $link = $project.DIRECTORY_SEPARATOR.'escape';
        set_error_handler(static fn(int $severity): bool => $severity === E_WARNING);
        try {
            $linked = is_string($outside) && symlink($outside, $link);
        } finally {
            restore_error_handler();
        }

        if ($linked) {
            expect(fn () => $policy->projectFile('escape'))->toThrow(RuntimeException::class);
        }
    } finally {
        TempProject::remove($project);

        if (is_string($outside) && file_exists($outside)) {
            unlink($outside);
        }
    }
});
