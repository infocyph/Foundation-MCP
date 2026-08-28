<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Resource;

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Security\PathPolicy;
use Infocyph\FoundationMcp\Security\Redactor;
use Infocyph\FoundationMcp\Security\SecretPolicy;
use InvalidArgumentException;
use RuntimeException;

/**
 * @phpstan-type ResourceRead array{
 *     scope:string,
 *     package:?string,
 *     path:string,
 *     start_line:int,
 *     end_line:int,
 *     total_lines:int,
 *     bytes:int,
 *     fingerprint:string,
 *     truncated:bool,
 *     content:string
 * }
 */
final readonly class ResourceReader
{
    private const int DEFAULT_LINES = 200;

    private const int MAX_BYTES = 1_048_576;

    private const int MAX_LINES = 400;

    private Redactor $redactor;

    private SecretPolicy $secrets;

    public function __construct(
        private Project $project,
        private ComposerInspector $composer,
        ?SecretPolicy $secrets = null,
        ?Redactor $redactor = null,
    ) {
        $this->secrets = $secrets ?? new SecretPolicy();
        $this->redactor = $redactor ?? new Redactor();
    }

    /** @return ResourceRead */
    public function package(string $package, string $path, int $startLine = 1, ?int $endLine = null): array
    {
        $installed = $this->composer->package($package);

        if ($installed?->installPath === null) {
            throw new RuntimeException('Package is not installed with a source root.');
        }

        $this->secrets->assertAllowed($path);
        $paths = new PathPolicy($this->project->root, [$package => $installed->installPath]);
        $resolved = $paths->packageFile($package, $path);

        return $this->read(
            $resolved,
            $this->relative($resolved, $installed->installPath),
            'package',
            $package,
            $startLine,
            $endLine,
        );
    }

    /** @return ResourceRead */
    public function project(string $path, int $startLine = 1, ?int $endLine = null): array
    {
        $this->secrets->assertAllowed($path);
        $paths = new PathPolicy($this->project->root);
        $resolved = $paths->projectFile($path);

        return $this->read(
            $resolved,
            $this->relative($resolved, $this->project->root),
            'project',
            null,
            $startLine,
            $endLine,
        );
    }

    /** @return ResourceRead */
    private function read(
        string $resolved,
        string $path,
        string $scope,
        ?string $package,
        int $startLine,
        ?int $endLine,
    ): array {
        if ($startLine < 1) {
            throw new InvalidArgumentException('Start line must be at least 1.');
        }

        $endLine ??= $startLine + self::DEFAULT_LINES - 1;

        if ($endLine < $startLine) {
            throw new InvalidArgumentException('End line must be greater than or equal to start line.');
        }

        if (($endLine - $startLine + 1) > self::MAX_LINES) {
            throw new InvalidArgumentException('A resource read may contain at most 400 lines.');
        }

        $size = filesize($resolved);

        if ($size === false) {
            throw new RuntimeException('Unable to determine resource size.');
        }

        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('Resource exceeds the 1 MiB read limit.');
        }

        $content = file_get_contents($resolved);

        if ($content === false) {
            throw new RuntimeException('Unable to read resource.');
        }

        if (strlen($content) > self::MAX_BYTES) {
            throw new RuntimeException('Resource exceeds the 1 MiB read limit.');
        }

        if (str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
            throw new RuntimeException('Binary resource cannot be read as text.');
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $normalized);
        $total = count($lines);

        if ($startLine > $total) {
            throw new InvalidArgumentException('Start line exceeds the resource length.');
        }

        $actualEnd = min($endLine, $total);
        $slice = array_slice($lines, $startLine - 1, $actualEnd - $startLine + 1);
        $excerpt = $this->redactor->redact(implode("\n", $slice));

        return [
            'scope' => $scope,
            'package' => $package,
            'path' => $path,
            'start_line' => $startLine,
            'end_line' => $actualEnd,
            'total_lines' => $total,
            'bytes' => $size,
            'fingerprint' => hash('sha256', $content),
            'truncated' => $startLine > 1 || $actualEnd < $total,
            'content' => $excerpt,
        ];
    }

    private function relative(string $path, string $root): string
    {
        $path = str_replace('\\', '/', rtrim($path, '/\\'));
        $root = str_replace('\\', '/', rtrim($root, '/\\'));

        return substr($path, strlen($root) + 1);
    }
}
