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
    private const int MAX_SOURCE_BYTES = 2_097_152;

    private readonly PathPolicy $projectPaths;
    private readonly SecretPolicy $secrets;
    private readonly Parser $parser;

    /** @var array<string, AnalyzedFile> */
    private array $cache = [];

    public function __construct(
        private readonly Project $project,
        private readonly ComposerInspector $composer,
        ?Parser $parser = null,
    ) {
        $this->projectPaths = new PathPolicy($project->root);
        $this->secrets = new SecretPolicy();
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function project(string $path): AnalyzedFile
    {
        return $this->source($this->projectPaths->projectFile($this->allowedPath($path)), 'project', null, $path);
    }

    public function package(string $package, string $path): AnalyzedFile
    {
        $roots = $this->composer->packageRoots([$package]);

        if (!isset($roots[$package])) {
            throw new RuntimeException('Package is not installed with an approved source root.');
        }

        $paths = new PathPolicy($this->project->root, $roots);

        return $this->source($paths->packageFile($package, $this->allowedPath($path)), 'package', $package, $path);
    }

    private function source(string $resolved, string $scope, ?string $package, string $path): AnalyzedFile
    {
        $source = $this->read($resolved);
        $fingerprint = hash('sha256', $source);
        $cacheKey = $scope."\0".($package ?? '')."\0".$resolved;
        $cached = $this->cache[$cacheKey] ?? null;

        if ($cached !== null && hash_equals($cached->fingerprint, $fingerprint)) {
            return $cached;
        }

        try {
            $nodes = $this->parser->parse($source);
        } catch (Error $error) {
            return $this->cache[$cacheKey] = $this->parseFailure($scope, $package, $path, $fingerprint, $source, $error);
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
            return $this->cache[$cacheKey] = $this->parseFailure($scope, $package, $path, $fingerprint, $source, $error);
        }

        return $this->cache[$cacheKey] = new AnalyzedFile(
            scope: $scope,
            package: $package,
            path: $this->relative($path),
            fingerprint: $fingerprint,
            bytes: strlen($source),
            namespaces: $declarations->namespaces(),
            imports: $declarations->imports(),
            declarations: $declarations->declarations(),
            references: $references->references(),
            literalArrays: $literals->arrays(),
            errors: [],
        );
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
            path: $this->relative($path),
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
        $size = filesize($path);

        if ($size !== false && $size > self::MAX_SOURCE_BYTES) {
            throw new RuntimeException('PHP source exceeds the 2 MiB analysis limit.');
        }

        $source = file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException('Unable to read PHP source.');
        }

        if (strlen($source) > self::MAX_SOURCE_BYTES) {
            throw new RuntimeException('PHP source exceeds the 2 MiB analysis limit.');
        }

        if (str_contains($source, "\0")) {
            throw new RuntimeException('Binary PHP source is not supported.');
        }

        return $source;
    }

    private function allowedPath(string $path): string
    {
        $this->secrets->assertAllowed($path);

        if (strtolower(pathinfo(str_replace('\\', '/', $path), PATHINFO_EXTENSION)) !== 'php') {
            throw new RuntimeException('PHP analysis accepts only .php source files.');
        }

        return $path;
    }

    private function relative(string $path): string
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
}
