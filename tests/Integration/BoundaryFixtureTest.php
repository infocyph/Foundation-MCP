<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Tests\Integration;

use Infocyph\FoundationMcp\Analysis\SourceFileFinder;
use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BoundaryFixtureTest extends TestCase
{
    public function testLargeSourceTreeIsDiscoveredDeterministically(): void
    {
        $root = TempProject::create(
            composer: [
                'name' => 'fixture/large-host',
                'require' => ['infocyph/foundation' => '^2.1'],
                'autoload' => ['psr-4' => ['App\\' => 'app/']],
            ],
            directories: ['app/generated'],
        );

        try {
            for ($index = 1; $index <= 512; ++$index) {
                file_put_contents(
                    $root . '/app/generated/Generated' . $index . '.php',
                    sprintf("<?php\nnamespace App\\Generated;\nfinal class Generated%d {}\n", $index),
                );
            }

            $project = (new ProjectDetector())->detect($root);
            $files = (new SourceFileFinder($project, new ComposerInspector($project)))->project();

            self::assertCount(512, $files);
            self::assertSame('app/generated/Generated1.php', array_key_first($files));
            self::assertArrayHasKey('app/generated/Generated512.php', $files);
        } finally {
            TempProject::remove($root);
        }
    }

    public function testExternalPathPackageAndSymlinkInstallResolveToApprovedSourceRoot(): void
    {
        $packageRoot = TempProject::create(
            composer: [
                'name' => 'vendor/path-package',
                'autoload' => ['psr-4' => ['Vendor\\PathPackage\\' => 'src/']],
            ],
            directories: ['src'],
            files: [
                'src/Thing.php' => '<?php namespace Vendor\\PathPackage; final class Thing {}',
            ],
        );
        $projectRoot = TempProject::create(
            composer: [
                'name' => 'fixture/path-host',
                'require' => [
                    'infocyph/foundation' => '^2.1',
                    'vendor/path-package' => '^1.0',
                ],
            ],
            directories: ['vendor/composer', 'vendor/vendor'],
            files: [
                'composer.lock' => json_encode([
                    'packages' => [[
                        'name' => 'vendor/path-package',
                        'version' => '1.0.0',
                    ]],
                    'packages-dev' => [],
                ], JSON_THROW_ON_ERROR),
                'vendor/composer/installed.json' => json_encode([
                    'packages' => [[
                        'name' => 'vendor/path-package',
                        'version' => '1.0.0',
                        'install-path' => $packageRoot,
                    ]],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        try {
            $project = (new ProjectDetector())->detect($projectRoot);
            $composer = new ComposerInspector($project);
            $package = $composer->package('vendor/path-package');

            self::assertNotNull($package);
            self::assertSame(realpath($packageRoot), $package->installPath);
            self::assertArrayHasKey(
                'src/Thing.php',
                (new SourceFileFinder($project, $composer))->package('vendor/path-package'),
            );

            $link = $projectRoot . '/vendor/vendor/path-package';
            $linked = false;
            set_error_handler(static fn(int $severity): bool => $severity === E_WARNING, E_WARNING);
            try {
                $linked = symlink($packageRoot, $link);
            } finally {
                restore_error_handler();
            }

            if ($linked) {
                file_put_contents(
                    $projectRoot . '/vendor/composer/installed.json',
                    json_encode([
                        'packages' => [[
                            'name' => 'vendor/path-package',
                            'version' => '1.0.0',
                            'install-path' => '../vendor/path-package',
                        ]],
                    ], JSON_THROW_ON_ERROR),
                );
                $composer = new ComposerInspector((new ProjectDetector())->detect($projectRoot));

                self::assertSame(realpath($packageRoot), $composer->package('vendor/path-package')?->installPath);
                self::assertArrayHasKey(
                    'src/Thing.php',
                    (new SourceFileFinder((new ProjectDetector())->detect($projectRoot), $composer))->package('vendor/path-package'),
                );
            }
        } finally {
            TempProject::remove($projectRoot);
            TempProject::remove($packageRoot);
        }
    }

    public function testPackageAutoloadRootCardinalityIsBounded(): void
    {
        $autoload = [];
        for ($index = 1; $index <= 1_025; ++$index) {
            $autoload[sprintf('Vendor\\Path%04d\\', $index)] = 'src/' . $index . '/';
        }

        $packageRoot = TempProject::create(
            composer: [
                'name' => 'vendor/oversized-package',
                'autoload' => ['psr-4' => $autoload],
            ],
        );
        $projectRoot = TempProject::create(
            composer: [
                'name' => 'fixture/oversized-package-host',
                'require' => [
                    'infocyph/foundation' => '^2.1',
                    'vendor/oversized-package' => '^1.0',
                ],
            ],
            directories: ['vendor/composer'],
            files: [
                'composer.lock' => json_encode([
                    'packages' => [[
                        'name' => 'vendor/oversized-package',
                        'version' => '1.0.0',
                    ]],
                    'packages-dev' => [],
                ], JSON_THROW_ON_ERROR),
                'vendor/composer/installed.json' => json_encode([
                    'packages' => [[
                        'name' => 'vendor/oversized-package',
                        'version' => '1.0.0',
                        'install-path' => $packageRoot,
                    ]],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        try {
            $project = (new ProjectDetector())->detect($projectRoot);
            $finder = new SourceFileFinder($project, new ComposerInspector($project));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('1,024 autoload-path limit');
            $finder->package('vendor/oversized-package');
        } finally {
            TempProject::remove($projectRoot);
            TempProject::remove($packageRoot);
        }
    }
}
