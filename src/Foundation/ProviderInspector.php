<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation;

use Infocyph\FoundationMcp\Analysis\SymbolIndex;
use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Security\PathPolicy;
use Infocyph\FoundationMcp\Security\SecretPolicy;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;

/**
 * Static, non-bootstrapping inspection of Foundation application providers.
 *
 * @phpstan-type ProviderEntry array{
 *   group:string,class:?string,source:string,line:int,status:string,effective_runtimes:list<string>,
 *   package:?string,declaration:?array{path:string,line:int},contract:string,dynamic_fields:list<string>
 * }
 * @phpstan-type Diagnostic array{code:string,source:?string,line:?int,message:string}
 */
final class ProviderInspector
{
    private const int MAX_DIAGNOSTICS = 100;

    private const int MAX_PROVIDERS = 1_000;

    private const int MAX_SOURCE_BYTES = 1_048_576;

    private readonly Parser $parser;

    private readonly PathPolicy $paths;

    private readonly SecretPolicy $secrets;

    private readonly SymbolIndex $symbols;

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    public function __construct(
        private readonly Project $project,
        private readonly ComposerInspector $composer,
        ?Parser $parser = null,
        ?SymbolIndex $symbols = null,
    ) {
        $this->parser = $parser ?? new ParserFactory()->createForNewestSupportedVersion();
        $this->paths = new PathPolicy($project->root);
        $this->secrets = new SecretPolicy();
        $this->symbols = $symbols ?? new SymbolIndex($project, $composer);
    }

    /**
     * @return array{
     *   groups:list<string>,providers:list<ProviderEntry>,effective:array<string,list<string>>,diagnostics:list<Diagnostic>
     * }
     */
    public function inspect(): array
    {
        $this->diagnostics = [];

        try {
            $groups = $this->installedGroups();
        } catch (RuntimeException $error) {
            $this->diagnostic('provider_contract_invalid', null, null, $error->getMessage());

            return ['groups' => [], 'providers' => [], 'effective' => [], 'diagnostics' => $this->diagnostics];
        }

        $providers = $this->projectProviders($groups);
        $effective = $this->effective($groups, $providers);
        $this->finalize($providers);

        return [
            'groups' => $groups,
            'providers' => $providers,
            'effective' => $effective,
            'diagnostics' => $this->diagnostics,
        ];
    }

    /**
     * @param list<ProviderEntry> $providers
     * @param list<string> $groups
     */
    private function appendGroup(array &$providers, string $group, Node\Expr\Array_ $array, array $groups, string $source): void
    {
        $seen = [];

        foreach ($array->items as $item) {
            if (count($providers) >= self::MAX_PROVIDERS) {
                return;
            }
            if (!$item instanceof Node\Expr\ArrayItem || $item->unpack) {
                $providers[] = $this->dynamicEntry($group, $groups, $source, $array->getStartLine());

                continue;
            }

            $class = $this->providerClass($item->value);
            if ($class === null) {
                $providers[] = $this->dynamicEntry($group, $groups, $source, $item->getStartLine());

                continue;
            }
            if (isset($seen[$class])) {
                continue;
            }
            $seen[$class] = true;
            $providers[] = $this->resolvedEntry($group, $class, $groups, $source, $item->getStartLine());
        }
    }

    private function diagnostic(string $code, ?string $source, ?int $line, string $message): void
    {
        if (count($this->diagnostics) < self::MAX_DIAGNOSTICS) {
            $this->diagnostics[] = compact('code', 'source', 'line', 'message');
        }
    }

    /** @param list<string> $groups @return ProviderEntry */
    private function dynamicEntry(string $group, array $groups, string $source, int $line): array
    {
        return [
            'group' => $group,
            'class' => null,
            'source' => $source,
            'line' => $line,
            'status' => 'dynamic',
            'effective_runtimes' => $this->effectiveRuntimes($group, $groups),
            'package' => null,
            'declaration' => null,
            'contract' => 'unknown',
            'dynamic_fields' => ['class'],
        ];
    }

    /**
     * @param list<string> $groups
     * @param list<ProviderEntry> $providers
     * @return array<string,list<string>>
     */
    private function effective(array $groups, array $providers): array
    {
        $runtimeGroups = array_values(array_filter($groups, static fn(string $group): bool => $group !== 'common'));
        $effective = array_fill_keys($runtimeGroups, []);

        foreach ($runtimeGroups as $runtime) {
            $seen = [];
            foreach ($providers as $provider) {
                if ($provider['class'] === null || ($provider['group'] !== 'common' && $provider['group'] !== $runtime)) {
                    continue;
                }
                if (!isset($seen[$provider['class']])) {
                    $seen[$provider['class']] = true;
                    $effective[$runtime][] = $provider['class'];
                }
            }
        }

        return $effective;
    }

    /** @param list<string> $groups @return list<string> */
    private function effectiveRuntimes(string $group, array $groups): array
    {
        if ($group !== 'common') {
            return [$group];
        }

        return array_values(array_filter($groups, static fn(string $item): bool => $item !== 'common'));
    }

    /** @param list<ProviderEntry> $providers */
    private function finalize(array &$providers): void
    {
        usort($providers, static fn(array $left, array $right): int
            => [$left['source'], $left['line'], $left['group'], $left['class'] ?? '']
            <=> [$right['source'], $right['line'], $right['group'], $right['class'] ?? '']);
        usort($this->diagnostics, static fn(array $left, array $right): int
            => [$left['source'] ?? '', $left['line'] ?? 0, $left['code'], $left['message']]
            <=> [$right['source'] ?? '', $right['line'] ?? 0, $right['code'], $right['message']]);
    }

    /**
     * @param list<string> $groups
     * @return list<ProviderEntry>
     */
    private function groupEntries(Node\Expr\Array_ $array, array $groups, string $source): array
    {
        $allowed = array_fill_keys($groups, true);
        $providers = [];

        foreach ($array->items as $item) {
            if (!$item instanceof Node\Expr\ArrayItem || $item->unpack) {
                $this->diagnostic('dynamic_unresolved', $source, $array->getStartLine(), 'Provider groups contain unsupported dynamic array syntax.');

                continue;
            }

            $group = $item->key instanceof Node\Scalar\String_ ? $item->key->value : null;
            if ($group === null) {
                $this->diagnostic('dynamic_unresolved', $source, $item->getStartLine(), 'Provider group name is dynamic.');

                continue;
            }
            if (!isset($allowed[$group])) {
                $this->diagnostic('provider_group_invalid', $source, $item->getStartLine(), sprintf('Unsupported provider group "%s".', $group));

                continue;
            }
            if (!$item->value instanceof Node\Expr\Array_) {
                $this->diagnostic('dynamic_unresolved', $source, $item->getStartLine(), sprintf('Provider group "%s" is not a literal provider list.', $group));

                continue;
            }

            $this->appendGroup($providers, $group, $item->value, $groups, $source);
            if (count($providers) >= self::MAX_PROVIDERS) {
                $this->diagnostic('output_limit_exceeded', $source, $item->getStartLine(), sprintf('Provider inspection is limited to %d declarations.', self::MAX_PROVIDERS));

                break;
            }
        }

        return $providers;
    }

    /** @return list<string> */
    private function installedGroups(): array
    {
        $foundation = $this->composer->foundation();
        if ($foundation?->installPath === null) {
            throw new RuntimeException('Installed Foundation provider contract is unavailable.');
        }

        $pathPolicy = new PathPolicy($this->project->root, ['infocyph/foundation' => $foundation->installPath]);
        $path = $pathPolicy->packageFile('infocyph/foundation', 'src/Application/ProviderFileLoader.php');
        $nodes = $this->parseRaw($path, 'infocyph/foundation:src/Application/ProviderFileLoader.php');
        if ($nodes === null) {
            throw new RuntimeException('Installed Foundation provider contract contains invalid PHP.');
        }

        foreach ($this->statements($nodes) as $node) {
            if (!$node instanceof Node\Stmt\Class_) {
                continue;
            }
            foreach ($node->getConstants() as $constant) {
                foreach ($constant->consts as $const) {
                    if ($const->name->toString() !== 'GROUPS') {
                        continue;
                    }
                    $groups = $this->stringArray($const->value);
                    if ($groups === null || $groups === [] || count($groups) > 32) {
                        throw new RuntimeException('Installed Foundation provider-group contract is invalid.');
                    }

                    return array_values(array_unique($groups));
                }
            }
        }

        throw new RuntimeException('Installed Foundation provider-group contract could not be read statically.');
    }

    private function packageForClass(string $class): ?string
    {
        $bestLength = -1;
        $best = [];

        foreach ($this->composer->packages() as $package) {
            $psr4 = $package->autoload['psr-4'] ?? null;
            if (!is_array($psr4)) {
                continue;
            }
            foreach ($psr4 as $prefix => $_paths) {
                if (!is_string($prefix) || $prefix === '') {
                    continue;
                }
                $normalized = rtrim($prefix, '\\') . '\\';
                if (!str_starts_with($class, $normalized)) {
                    continue;
                }
                $length = strlen($normalized);
                if ($length > $bestLength) {
                    $bestLength = $length;
                    $best = [$package->name];
                } elseif ($length === $bestLength) {
                    $best[] = $package->name;
                }
            }
        }

        $best = array_values(array_unique($best));

        return count($best) === 1 ? $best[0] : null;
    }

    /** @return list<Node\Stmt>|null */
    private function parseProject(string $path, string $source): ?array
    {
        $nodes = $this->parseRaw($path, $source);
        if ($nodes === null) {
            return null;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]));

        try {
            /** @var list<Node\Stmt> $resolved */
            $resolved = $traverser->traverse($nodes);

            return $resolved;
        } catch (Error $error) {
            $this->diagnostic('parse_error', $source, $error->getStartLine() ?: null, $error->getRawMessage());

            return null;
        }
    }

    /** @return list<Node\Stmt>|null */
    private function parseRaw(string $path, string $source): ?array
    {
        try {
            $nodes = $this->parser->parse($this->read($path));
        } catch (Error $error) {
            $this->diagnostic('parse_error', $source, $error->getStartLine() ?: null, $error->getRawMessage());

            return null;
        }

        if (!is_array($nodes)) {
            $this->diagnostic('parse_error', $source, null, 'PHP parser returned no syntax tree.');

            return null;
        }

        return $nodes;
    }

    /**
     * @param list<string> $groups
     * @return list<ProviderEntry>
     */
    private function projectProviders(array $groups): array
    {
        $relative = 'bootstrap/providers.php';
        $candidate = $this->project->root . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'providers.php';
        if (!is_file($candidate)) {
            return [];
        }

        try {
            $path = $this->paths->projectFile($relative);
            $this->secrets->assertAllowed($relative);
            $nodes = $this->parseProject($path, $relative);
        } catch (RuntimeException $error) {
            $this->diagnostic('provider_source_invalid', $relative, null, $error->getMessage());

            return [];
        }

        if ($nodes === null) {
            return [];
        }

        $return = $this->topLevelReturn($nodes);
        if (!$return?->expr instanceof Node\Expr\Array_) {
            $this->diagnostic(
                'dynamic_unresolved',
                $relative,
                $return?->getStartLine(),
                'Provider file must return a statically inspectable grouped array.',
            );

            return [];
        }

        return $this->groupEntries($return->expr, $groups, $relative);
    }

    private function providerClass(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Scalar\String_ && trim($expr->value) !== '') {
            return ltrim($expr->value, '\\');
        }
        if (
            $expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && strtolower($expr->name->toString()) === 'class'
        ) {
            $resolved = $expr->class->getAttribute('resolvedName');

            return ($resolved instanceof Node\Name ? $resolved : $expr->class)->toString();
        }

        return null;
    }

    private function read(string $path): string
    {
        $size = filesize($path);
        if ($size !== false && $size > self::MAX_SOURCE_BYTES) {
            throw new RuntimeException('Provider source exceeds the 1 MiB inspection limit.');
        }
        $source = file_get_contents($path);
        if ($source === false || strlen($source) > self::MAX_SOURCE_BYTES || str_contains($source, "\0")) {
            throw new RuntimeException('Provider source could not be read safely.');
        }

        return $source;
    }

    /** @param list<string> $groups @return ProviderEntry */
    private function resolvedEntry(string $group, string $class, array $groups, string $source, int $line): array
    {
        $matches = $this->symbols->find($class);
        $declaration = null;
        $contract = 'unknown';

        if (count($matches) === 1 && ($matches[0]['kind'] ?? null) === 'class') {
            $match = $matches[0];
            $declaration = ['path' => $match['path'], 'line' => $match['line']];
            $implements = $match['implements'] ?? [];
            $contract = in_array('Infocyph\\Foundation\\Application\\ServiceProviderInterface', $implements, true)
                ? 'implements'
                : 'not_proven';
        } elseif (count($matches) > 1) {
            $contract = 'ambiguous_declaration';
        }

        return [
            'group' => $group,
            'class' => $class,
            'source' => $source,
            'line' => $line,
            'status' => 'resolved',
            'effective_runtimes' => $this->effectiveRuntimes($group, $groups),
            'package' => $this->packageForClass($class),
            'declaration' => $declaration,
            'contract' => $contract,
            'dynamic_fields' => [],
        ];
    }

    /** @param list<Node\Stmt> $nodes @return list<Node\Stmt> */
    private function statements(array $nodes): array
    {
        $statements = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                array_push($statements, ...$this->statements($node->stmts));

                continue;
            }
            $statements[] = $node;
        }

        return $statements;
    }

    /** @return list<string>|null */
    private function stringArray(Node\Expr $expr): ?array
    {
        if (!$expr instanceof Node\Expr\Array_) {
            return null;
        }

        $strings = [];
        foreach ($expr->items as $item) {
            if (!$item instanceof Node\Expr\ArrayItem || $item->unpack || !$item->value instanceof Node\Scalar\String_) {
                return null;
            }
            $strings[] = $item->value->value;
        }

        return $strings;
    }

    /** @param list<Node\Stmt> $nodes */
    private function topLevelReturn(array $nodes): ?Node\Stmt\Return_
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Return_) {
                return $node;
            }
        }

        return null;
    }
}
