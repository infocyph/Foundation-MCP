<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Diagnostics;

use Composer\InstalledVersions;
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
    ) {
    }

    /**
     * @return list<array{name: string, ok: bool, detail: string}>
     */
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
                $checks[] = $this->sourceRootsCheck($project);
                $checks[] = $this->securityCheck($project);
            }
        } catch (Throwable $exception) {
            $checks[] = [
                'name' => 'Project root',
                'ok' => false,
                'detail' => $exception->getMessage(),
            ];
        }

        return $checks;
    }

    public function run(): int
    {
        $failed = false;

        foreach ($this->checks() as $check) {
            $failed = $failed || !$check['ok'];
            fwrite(
                STDERR,
                sprintf(
                    "[%s] %s: %s%s",
                    $check['ok'] ? 'OK' : 'FAIL',
                    $check['name'],
                    $check['detail'],
                    PHP_EOL,
                ),
            );
        }

        return $failed ? 1 : 0;
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function phpCheck(): array
    {
        $ok = PHP_VERSION_ID >= 80400;

        return [
            'name' => 'PHP',
            'ok' => $ok,
            'detail' => PHP_VERSION.($ok ? '' : ' (8.4+ required)'),
        ];
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function packageCheck(string $package, string $label): array
    {
        try {
            $installed = InstalledVersions::isInstalled($package);
            $version = $installed
                ? InstalledVersions::getPrettyVersion($package) ?? InstalledVersions::getVersion($package) ?? 'installed'
                : 'not installed';
        } catch (Throwable $exception) {
            return [
                'name' => $label,
                'ok' => false,
                'detail' => 'Composer metadata unavailable: '.$exception->getMessage(),
            ];
        }

        return [
            'name' => $label,
            'ok' => $installed,
            'detail' => $version,
        ];
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function parserCheck(): array
    {
        if (!class_exists(ParserFactory::class)) {
            return [
                'name' => 'Parser capability',
                'ok' => false,
                'detail' => ParserFactory::class.' unavailable',
            ];
        }

        try {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $nodes = $parser->parse('<?php final class FoundationMcpParserProbe {}');
            $ok = is_array($nodes) && $nodes !== [];
        } catch (Throwable $exception) {
            return [
                'name' => 'Parser capability',
                'ok' => false,
                'detail' => 'Parser compatibility failure: '.$exception->getMessage(),
            ];
        }

        return [
            'name' => 'Parser capability',
            'ok' => $ok,
            'detail' => $ok ? 'parse probe passed' : 'parse probe returned no nodes',
        ];
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function projectCheck(Project $project): array
    {
        return [
            'name' => 'Project detection',
            'ok' => $project->supported(),
            'detail' => $project->hostType->value,
        ];
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function sourceRootsCheck(Project $project): array
    {
        $roots = SourceRoots::discover($project);

        return [
            'name' => 'Source roots',
            'ok' => $roots->all() !== [],
            'detail' => count($roots->all()).' approved roots',
        ];
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function securityCheck(Project $project): array
    {
        try {
            $paths = new PathPolicy($project->root);
            $paths->projectFile('composer.json');

            $secrets = new SecretPolicy();
            $ok = $secrets->denied('.env') && !$secrets->denied('.env.example');
        } catch (Throwable $exception) {
            return [
                'name' => 'Path/security policy',
                'ok' => false,
                'detail' => $exception->getMessage(),
            ];
        }

        return [
            'name' => 'Path/security policy',
            'ok' => $ok,
            'detail' => $ok ? 'ready' : 'secret policy invariant failed',
        ];
    }
}
