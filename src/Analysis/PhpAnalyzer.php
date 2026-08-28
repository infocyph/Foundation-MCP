<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Analysis;

use Infocyph\FoundationMcp\Analysis\Internal\PhpDeclarationVisitor;
use Infocyph\FoundationMcp\Analysis\Internal\PhpLiteralVisitor;
use Infocyph\FoundationMcp\Analysis\Internal\PhpReferenceVisitor;
use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Security\PathPolicy;
use Infocyph\FoundationMcp\Security\SecretPolicy;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;

final class PhpAnalyzer
{
    private const int MAX_CACHE_ENTRIES = 256;

    private const int MAX_SOURCE_BYTES = 2_097_152;

    private readonly Parser $parser;

    private readonly PathPolicy $projectPaths;

    private readonly SecretPolicy $secrets;

    /** @var array<string, AnalyzedFile> */
    private array $cache = [];

    /** @var list<string> */
    private array $cacheOrder = [];

    public function __construct(
        private readonly Project $project,
        private readonly ComposerInspector $composer,
        ?Parser $parser = null,
    ) {
        $this->projectPaths = new PathPolicy($project->root);
        $this->secrets = new SecretPolicy();
        $this->parser = $parser ?? new ParserFactory()->createForNewestSupportedVersion();
    }

    public function package(string $package, string $path): AnalyzedFile
    {
        $path = $this->allowedPath($path);
        $roots = $this->composer->packageRoots([$package]);

        if (!isset($roots[$package])) {
            throw new RuntimeException('Package is not installed with an approved source root.');
        }

        $paths = new PathPolicy($this->project->root, $roots);

        return $this->analyze(
            $this->read($paths->packageFile($package, $path)),
            'package',
            $package,
            $path,
            'package-file:' . $package . ':' . $path,
        );
    }

    public function project(string $path): AnalyzedFile
    {
        $path = $this->allowedPath($path);

        return $this->analyze(
            $this->read($this->projectPaths->projectFile($path)),
            'project',
            null,
            $path,
            'project-file:' . $path,
        );
    }

    /**
     * Analyze trusted in-memory PHP text obtained through another approved read boundary, such as Git HEAD.
     * The path is still subjected to the normal PHP/secret policy and no source is executed.
     */
    public function text(string $path, string $source, string $scope = 'project', ?string $package = null): AnalyzedFile
    {
        $path = $this->allowedPath($path);
        $this->assertSource($source);

        return $this->analyze($source, $scope, $package, $path, 'text:' . $scope . ':' . ($package ?? '') . ':' . $path);
    }

    private function allowedPath(string $path): string
    {
        $this->secrets->assertAllowed($path);

        if (strtolower(pathinfo(str_replace('\\', '/', $path), PATHINFO_EXTENSION)) !== 'php') {
            throw new RuntimeException('PHP analysis accepts only .php source files.');
        }

        return $path;
    }

    private function analyze(string $source, string $scope, ?string $package, string $path, string $cacheIdentity): AnalyzedFile
    {
        $fingerprint = hash('sha256', $source);
        $cached = $this->cache[$cacheIdentity] ?? null;

        if ($cached !== null && hash_equals($cached->fingerprint, $fingerprint)) {
            return $cached;
        }

        try {
            $nodes = $this->parser->parse($source);
        } catch (Error $error) {
            return $this->remember(
                $cacheIdentity,
                $this->parseFailure($scope, $package, $path, $fingerprint, $source, $error),
            );
        }

        if (!is_array($nodes)) {
            throw new RuntimeException('PHP parser returned no syntax tree.');
        }

        $declarations = new PhpDeclarationVisitor();
        $references = new PhpReferenceVisitor();
        $literals = new PhpLiteralVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, [
            'preserveOriginalNames' => true,
            'replaceNodes' => false,
        ]));
        $traverser->addVisitor($declarations);
        $traverser->addVisitor($references);
        $traverser->addVisitor($literals);

        try {
            $traverser->traverse($nodes);
        } catch (Error $error) {
            return $this->remember(
                $cacheIdentity,
                $this->parseFailure($scope, $package, $path, $fingerprint, $source, $error),
            );
        }

        return $this->remember($cacheIdentity, new AnalyzedFile(
            scope: $scope,
            package: $package,
            path: $this->normalizeRelativePath($path),
            fingerprint: $fingerprint,
            bytes: strlen($source),
            namespaces: $declarations->namespaces(),
            imports: $declarations->imports(),
            declarations: $declarations->declarations(),
            references: $references->references(),
            literalArrays: $literals->arrays(),
            errors: [],
        ));
    }

    private function assertSource(string $source): void
    {
        if (strlen($source) > self::MAX_SOURCE_BYTES) {
            throw new RuntimeException('PHP source exceeds the 2 MiB analysis limit.');
        }

        if (str_contains($source, "\0")) {
            throw new RuntimeException('Binary PHP source is not supported.');
        }
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function parseFailure(
        string $scope,
        ?string $package,
        string $path,
        string $fingerprint,
        string $source,
        Error $error,
    ): AnalyzedFile {
        return new AnalyzedFile(
            scope: $scope,
            package: $package,
            path: $this->normalizeRelativePath($path),
            fingerprint: $fingerprint,
            bytes: strlen($source),
            namespaces: [],
            imports: [],
            declarations: [],
            references: [],
            literalArrays: [],
            errors: [[
                'code' => 'parse_error',
                'message' => $error->getRawMessage(),
                'line' => $error->getStartLine() > 0 ? $error->getStartLine() : null,
            ]],
        );
    }

    private function read(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to read PHP source.');
        }

        try {
            $source = stream_get_contents($handle, self::MAX_SOURCE_BYTES + 1);
        } finally {
            fclose($handle);
        }

        if (!is_string($source)) {
            throw new RuntimeException('Unable to read PHP source.');
        }

        $this->assertSource($source);

        return $source;
    }

    private function remember(string $key, AnalyzedFile $file): AnalyzedFile
    {
        if (!isset($this->cache[$key])) {
            if (count($this->cacheOrder) >= self::MAX_CACHE_ENTRIES) {
                $oldest = array_shift($this->cacheOrder);

                if (is_string($oldest)) {
                    unset($this->cache[$oldest]);
                }
            }

            $this->cacheOrder[] = $key;
        }

        $this->cache[$key] = $file;

        return $file;
    }
}
