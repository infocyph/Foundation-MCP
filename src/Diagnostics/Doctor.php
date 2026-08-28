<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Diagnostics;

use Composer\InstalledVersions;
use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Foundation\ModuleCatalogReader;
use Infocyph\FoundationMcp\Git\GitRunner;
use Infocyph\FoundationMcp\Mcp\ServerFactory;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Project\ProjectLocator;
use Infocyph\FoundationMcp\Project\SourceRoots;
use Infocyph\FoundationMcp\Security\PathPolicy;
use Infocyph\FoundationMcp\Security\SecretPolicy;
use PhpParser\ParserFactory;
use Throwable;

final readonly class Doctor
{
    public function __construct(
        private ?string $root = null,
        private bool $gitEnabled = true,
    ) {
    }

    /** @return list<array{name:string,ok:bool,detail:string}> */
    public function checks(): array
    {
        $checks = [
            $this->phpCheck(),
            $this->packageCheck('mcp/sdk', 'Official MCP SDK'),
            $this->packageCheck('infocyph/phpforge', 'PHPForge'),
            $this->packageCheck('nikic/php-parser', 'PHP parser backend'),
            $this->parserCheck(),
        ];

        try {
            $root = (new ProjectLocator())->locate($this->root);
            $project = (new ProjectDetector())->detect($root);
            $checks[] = $this->projectCheck($project);

            if ($project->supported()) {
                $composer = new ComposerInspector($project);
                $checks[] = $this->composerCheck($composer);
                $checks[] = $this->foundationCheck($composer);
                $checks[] = $this->moduleCatalogCheck($project, $composer);
                $checks[] = $this->sourceRootsCheck($project);
                $checks[] = $this->securityCheck($project, $composer);
                $checks[] = $this->gitCheck($project);
                $checks[] = $this->serverCheck($project);
            }
        } catch (Throwable $exception) {
            $checks[] = ['name' => 'Project root', 'ok' => false, 'detail' => $exception->getMessage()];
        }

        return $checks;
    }

    public function run(): int
    {
        $failed = false;
        foreach ($this->checks() as $check) {
            $failed = $failed || !$check['ok'];
            fwrite(STDERR, sprintf("[%s] %s: %s%s", $check['ok'] ? 'OK' : 'FAIL', $check['name'], $check['detail'], PHP_EOL));
        }
        return $failed ? 1 : 0;
    }

    /** @return array{name:string,ok:bool,detail:string} */
    private function phpCheck(): array
    {
        $ok = PHP_VERSION_ID >= 80400;
        return ['name' => 'PHP', 'ok' => $ok, 'detail' => PHP_VERSION.($ok ? '' : ' (8.4+ required)')];
    }

    /** @return array{name:string,ok:bool,detail:string} */
    private function packageCheck(string $package, string $label): array
    {
        try {
            $installed = InstalledVersions::isInstalled($package);
            $version = $installed
                ? InstalledVersions::getPrettyVersion($package) ?? InstalledVersions::getVersion($package) ?? 'installed'
                : 'not installed';
        } catch (Throwable $exception) {
            return ['name' => $label, 'ok' => false, 'detail' => 'Composer metadata unavailable: '.$exception->getMessage()];
        }

        return ['name' => $label, 'ok' => $installed, 'detail' => $version];
    }

    /** @return array{name:string,ok:bool,detail:string} */
    private function parserCheck(): array
    {
        if (!class_exists(ParserFactory::class)) {
            return ['name' => 'Parser capability', 'ok' => false, 'detail' => ParserFactory::class.' unavailable'];
        }

        try {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $nodes = $parser->parse('<?php final class FoundationMcpParserProbe {}');
            $ok = is_array($nodes) && $nodes !== [];
        } catch (Throwable $exception) {
            return ['name' => 'Parser capability', 'ok' => false, 'detail' => 'Parser compatibility failure: '.$exception->getMessage()];
        }

        return ['name' => 'Parser capability', 'ok' => $ok, 'detail' => $ok ? 'parse probe passed' : 'parse probe returned no nodes'];
    }

    /** @return array{name:string,ok:bool,detail:string} */
    private function projectCheck(Project $project): array
    {
        return ['name' => 'Project detection', 'ok' => $project->supported(), 'detail' => $project->hostType->value];
    }

    /** @return array{name:string,ok:bool,detail:string} */
    private function composerCheck(ComposerInspector $composer): array
    {
        $diagnostics = $composer->diagnostics();
        $ok = $composer->lockPresent() && $composer->installedMetadataPresent() && $diagnostics === [];
        $detail = $ok
            ? count($composer->packages()).' packages; lock/install state aligned'
            : implode(', ', array_slice(array_column($diagnostics, 'code'), 0, 4));

        return ['name' => 'Composer state', 'ok' => $ok, 'detail' => $detail !== '' ? $detail : 'Composer metadata incomplete'];
    }

    /** @return array{name:string,ok:bool,detail:string} */
    private function foundationCheck(ComposerInspector $composer): array
    {
        $foundation = $composer->foundation();
        $ok = $foundation !== null
            && $foundation->declaredConstraint !== null
            && $foundation->lockedVersion !== null
            && $foundation->installedVersion !== null
            && $foundation->installPath !== null
            && $foundation->state() === 'matched';

        $detail = $foundation === null
            ? 'not declared, locked or installed'
            : sprintf(
                'declared=%s locked=%s installed=%s state=%s',
                $foundation->declaredConstraint ?? 'none',
                $foundation->lockedVersion ?? 'none',
                $foundation->installedVersion ?? 'none',
                $foundation->state(),
            );

        return ['name' => 'Foundation package', 'ok' => $ok, 'detail' => $detail];
    }

    /** @return array{name:string,ok:bool,detail:string} */
    private function moduleCatalogCheck(Project $project, ComposerInspector $composer): array
    {
        try {
            $modules = (new ModuleCatalogReader($project, $composer))->definitions();
        } catch (Throwable $exception) {
            return ['name' => 'Foundation ModuleCatalog', 'ok' => false, 'detail' => $exception->getMessage()];
        }

        return ['name' => 'Foundation ModuleCatalog', 'ok' => $modules !== [], 'detail' => count($modules).' purpose-first modules parsed statically'];
    }

    /** @return array{name:string,ok:bool,detail:string} */
    private function sourceRootsCheck(Project $project): array
    {
        $roots = SourceRoots::discover($project);
        return ['name' => 'Source roots', 'ok' => $roots->all() !== [], 'detail' => count($roots->all()).' approved roots'];
    }

    /** @return array{name:string,ok:bool,detail:string} */
    private function securityCheck(Project $project, ComposerInspector $composer): array
    {
        try {
            $paths = new PathPolicy($project->root, $composer->packageRoots());
            $paths->projectFile('composer.json');
            $foundation = $composer->foundation();
            if ($foundation?->installPath !== null) {
                $paths->packageDirectory('infocyph/foundation', '.');
            }
            $secrets = new SecretPolicy();
            $ok = $secrets->denied('.env') && !$secrets->denied('.env.example');
        } catch (Throwable $exception) {
            return ['name' => 'Path/security policy', 'ok' => false, 'detail' => $exception->getMessage()];
        }

        return ['name' => 'Path/security policy', 'ok' => $ok, 'detail' => $ok ? count($paths->packageRoots()).' package roots approved' : 'secret policy invariant failed'];
    }

    /** @return array{name:string,ok:bool,detail:string} */
    private function gitCheck(Project $project): array
    {
        if (!$this->gitEnabled) {
            return ['name' => 'Git inspection', 'ok' => true, 'detail' => 'disabled by --no-git'];
        }

        try {
            $available = (new GitRunner($project))->available();
        } catch (Throwable $exception) {
            return ['name' => 'Git inspection', 'ok' => false, 'detail' => $exception->getMessage()];
        }

        return ['name' => 'Git inspection', 'ok' => $available, 'detail' => $available ? 'read-only Git boundary available' : 'Git is unavailable'];
    }

    /** @return array{name:string,ok:bool,detail:string} */
    private function serverCheck(Project $project): array
    {
        try {
            (new ServerFactory($project, $this->gitEnabled))->create();
        } catch (Throwable $exception) {
            return ['name' => 'MCP server surface', 'ok' => false, 'detail' => $exception->getMessage()];
        }

        return ['name' => 'MCP server surface', 'ok' => true, 'detail' => 'explicit tools/resources build successfully'];
    }
}
