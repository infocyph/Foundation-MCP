<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation;

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Foundation\Internal\InstalledScheduleContract;
use Infocyph\FoundationMcp\Foundation\Internal\RouteValueResolver;
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
 * @phpstan-type ScheduleEntry array{
 *   command:?string,arguments:?list<string>,cron:?string,timezone:?string,key:?string,identity:?string,identity_status:string,
 *   without_overlap:?bool,on_one_server:?bool,overlap_lease_seconds:?float,overlap_wait_seconds:?float,
 *   timeout_seconds:?float,memory_limit_megabytes:?int,source:string,line:int,status:string,conditional:bool,dynamic_fields:list<string>
 * }
 * @phpstan-type Diagnostic array{code:string,source:?string,line:?int,message:string}
 */
final class ScheduleInspector
{
    private const int MAX_SOURCE_BYTES = 1_048_576;
    private const int MAX_ENTRIES = 500;
    private const int MAX_DIAGNOSTICS = 100;

    private readonly Parser $parser;
    private readonly PathPolicy $paths;
    private readonly SecretPolicy $secrets;
    private readonly RouteValueResolver $values;

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    /** @var array<string,true> */
    private array $fluent = [];

    /** @var list<ScheduleEntry> */
    private array $entries = [];

    /** @var array<string,int> */
    private array $entryVariables = [];

    public function __construct(
        private readonly Project $project,
        private readonly ComposerInspector $composer,
        ?Parser $parser = null,
    ) {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
        $this->paths = new PathPolicy($project->root);
        $this->secrets = new SecretPolicy();
        $this->values = new RouteValueResolver();
    }

    /** @return array{route_file:?string,entries:list<ScheduleEntry>,diagnostics:list<Diagnostic>} */
    public function inspect(): array
    {
        $this->diagnostics = [];
        $this->entries = [];
        $this->entryVariables = [];

        $contract = (new InstalledScheduleContract($this->project, $this->composer, $this->parser))->read();
        $this->diagnostics = $contract['diagnostics'];
        $this->fluent = $contract['fluent_methods'];
        $routeFile = $contract['route_file'];
        if ($routeFile === null) {
            return ['route_file' => null, 'entries' => [], 'diagnostics' => $this->diagnostics];
        }

        $candidate = $this->project->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $routeFile);
        if (!is_file($candidate)) {
            return ['route_file' => $routeFile, 'entries' => [], 'diagnostics' => $this->diagnostics];
        }
        try {
            $path = $this->paths->projectFile($routeFile);
            $this->secrets->assertAllowed($routeFile);
        } catch (RuntimeException $error) {
            $this->diagnostic('schedule_source_invalid', $routeFile, null, $error->getMessage());
            return ['route_file' => $routeFile, 'entries' => [], 'diagnostics' => $this->diagnostics];
        }

        $nodes = $this->parse($path, $routeFile);
        if ($nodes !== null) {
            $this->scanDefinition($nodes, $routeFile);
        }
        foreach ($this->entries as &$entry) {
            $this->finalize($entry);
        }
        unset($entry);
        usort($this->entries, static fn(array $left, array $right): int => [$left['line'], $left['command'] ?? ''] <=> [$right['line'], $right['command'] ?? '']);

        return ['route_file' => $routeFile, 'entries' => $this->entries, 'diagnostics' => $this->diagnostics];
    }

    /** @param list<Node\Stmt> $nodes */
    private function scanDefinition(array $nodes, string $source): void
    {
        $return = $this->topLevelReturn($nodes);
        if ($return?->expr instanceof Node\Expr\Closure || $return?->expr instanceof Node\Expr\ArrowFunction) {
            $callback = $return->expr;
            $parameter = $callback->params[0] ?? null;
            $schedule = $parameter instanceof Node\Param && $parameter->var instanceof Node\Expr\Variable && is_string($parameter->var->name)
                ? $parameter->var->name
                : 'schedule';
            $statements = $callback instanceof Node\Expr\Closure
                ? $callback->stmts
                : [new Node\Stmt\Expression($callback->expr)];
            $this->scanStatements($statements, [$schedule => true], false, $source);
            return;
        }

        $scheduleVariables = [];
        foreach ($nodes as $statement) {
            if ($statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\Assign
                && $statement->expr->var instanceof Node\Expr\Variable && is_string($statement->expr->var->name)
                && $statement->expr->expr instanceof Node\Expr\New_ && $this->isClass($statement->expr->expr->class, 'Infocyph\\Foundation\\Scheduling\\Schedule')) {
                $scheduleVariables[$statement->expr->var->name] = true;
            }
        }
        if ($scheduleVariables === []) {
            $this->diagnostic('schedule_dynamic', $source, $return?->getStartLine(), 'Schedule route file does not expose a statically inspectable Schedule/callable definition.');
            return;
        }
        $this->scanStatements($nodes, $scheduleVariables, false, $source);
    }

    /** @param list<Node\Stmt> $statements @param array<string,true> $scheduleVariables */
    private function scanStatements(array $statements, array $scheduleVariables, bool $conditional, string $source): void
    {
        foreach ($statements as $statement) {
            if (count($this->entries) >= self::MAX_ENTRIES) {
                $this->limitDiagnostic();
                return;
            }
            if ($statement instanceof Node\Stmt\Expression) {
                $this->scanExpression($statement->expr, $scheduleVariables, $conditional, $source);
                continue;
            }
            if ($statement instanceof Node\Stmt\If_) {
                $this->scanStatements($statement->stmts, $scheduleVariables, true, $source);
                foreach ($statement->elseifs as $elseif) {
                    $this->scanStatements($elseif->stmts, $scheduleVariables, true, $source);
                }
                if ($statement->else !== null) {
                    $this->scanStatements($statement->else->stmts, $scheduleVariables, true, $source);
                }
                continue;
            }
            if ($statement instanceof Node\Stmt\Foreach_ || $statement instanceof Node\Stmt\For_ || $statement instanceof Node\Stmt\While_ || $statement instanceof Node\Stmt\Do_) {
                $this->scanStatements($statement->stmts, $scheduleVariables, true, $source);
                continue;
            }
            if ($statement instanceof Node\Stmt\TryCatch) {
                $this->scanStatements($statement->stmts, $scheduleVariables, true, $source);
                foreach ($statement->catches as $catch) {
                    $this->scanStatements($catch->stmts, $scheduleVariables, true, $source);
                }
                if ($statement->finally !== null) {
                    $this->scanStatements($statement->finally->stmts, $scheduleVariables, true, $source);
                }
            }
        }
    }

    /** @param array<string,true> $scheduleVariables */
    private function scanExpression(Node\Expr $expr, array $scheduleVariables, bool $conditional, string $source): void
    {
        if ($expr instanceof Node\Expr\Assign && $expr->var instanceof Node\Expr\Variable && is_string($expr->var->name)) {
            $index = $this->entryFromExpression($expr->expr, $scheduleVariables, $conditional, $source);
            if ($index !== null) {
                $this->entryVariables[$expr->var->name] = $index;
            }
            return;
        }
        $this->entryFromExpression($expr, $scheduleVariables, $conditional, $source);
    }

    /** @param array<string,true> $scheduleVariables */
    private function entryFromExpression(Node\Expr $expr, array $scheduleVariables, bool $conditional, string $source): ?int
    {
        if (!$expr instanceof Node\Expr\MethodCall) {
            return null;
        }
        [$base, $calls] = $this->chain($expr);
        if ($base instanceof Node\Expr\Variable && is_string($base->name) && isset($scheduleVariables[$base->name])) {
            $first = $calls[0] ?? null;
            if (!$first instanceof Node\Expr\MethodCall || !$first->name instanceof Node\Identifier) {
                return null;
            }
            $method = strtolower($first->name->toString());
            if ($method === 'command') {
                $entry = $this->newEntry($first, $conditional, $source);
                foreach (array_slice($calls, 1) as $call) {
                    $this->apply($entry, $call, $source);
                }
                $this->entries[] = $entry;
                return array_key_last($this->entries);
            }
            if ($method === 'add') {
                $arg = $first->args[0]->value ?? null;
                if ($arg instanceof Node\Expr) {
                    $entry = $this->fromScheduledCommandExpression($arg, $conditional, $source);
                    if ($entry !== null) {
                        $this->entries[] = $entry;
                        return array_key_last($this->entries);
                    }
                }
            }
            return null;
        }

        if ($base instanceof Node\Expr\Variable && is_string($base->name) && isset($this->entryVariables[$base->name])) {
            $index = $this->entryVariables[$base->name];
            foreach ($calls as $call) {
                $this->apply($this->entries[$index], $call, $source);
            }
            $this->entries[$index]['conditional'] = $this->entries[$index]['conditional'] || $conditional;
            return $index;
        }

        return null;
    }

    /** @return ScheduleEntry|null */
    private function fromScheduledCommandExpression(Node\Expr $expr, bool $conditional, string $source): ?array
    {
        $calls = [];
        while ($expr instanceof Node\Expr\MethodCall) {
            array_unshift($calls, $expr);
            $expr = $expr->var;
        }
        if (!$expr instanceof Node\Expr\New_ || !$this->isClass($expr->class, 'Infocyph\\Foundation\\Scheduling\\ScheduledCommand')) {
            return null;
        }
        $entry = $this->baseEntry($conditional, $source, $expr->getStartLine());
        $command = $expr->args[0]->value ?? null;
        $entry['command'] = $command instanceof Node\Expr ? $this->values->stringValue($command) : null;
        if ($entry['command'] === null) {
            $entry['dynamic_fields'][] = 'command';
        }
        foreach ($calls as $call) {
            $this->apply($entry, $call, $source);
        }
        return $entry;
    }

    /** @return ScheduleEntry */
    private function newEntry(Node\Expr\MethodCall $call, bool $conditional, string $source): array
    {
        $entry = $this->baseEntry($conditional, $source, $call->getStartLine());
        $command = $call->args[0]->value ?? null;
        $entry['command'] = $command instanceof Node\Expr ? $this->values->stringValue($command) : null;
        if ($entry['command'] === null) {
            $entry['dynamic_fields'][] = 'command';
        }
        return $entry;
    }

    /** @return ScheduleEntry */
    private function baseEntry(bool $conditional, string $source, int $line): array
    {
        return [
            'command' => null,
            'arguments' => [],
            'cron' => '* * * * *',
            'timezone' => null,
            'key' => null,
            'identity' => null,
            'identity_status' => 'runtime_default_timezone',
            'without_overlap' => false,
            'on_one_server' => false,
            'overlap_lease_seconds' => 300.0,
            'overlap_wait_seconds' => 0.0,
            'timeout_seconds' => null,
            'memory_limit_megabytes' => null,
            'source' => $source,
            'line' => $line,
            'status' => 'resolved',
            'conditional' => $conditional,
            'dynamic_fields' => [],
        ];
    }

    /** @param ScheduleEntry $entry */
    private function apply(array &$entry, Node\Expr\MethodCall $call, string $source): void
    {
        if (!$call->name instanceof Node\Identifier) {
            $entry['dynamic_fields'][] = 'fluent_method';
            return;
        }
        $method = strtolower($call->name->toString());
        if (!isset($this->fluent[$method])) {
            $this->diagnostic('schedule_dynamic', $source, $call->getStartLine(), sprintf('Schedule fluent method "%s" is not part of the installed ScheduledCommand contract.', $call->name->toString()));
            $entry['dynamic_fields'][] = 'fluent:'.$method;
            return;
        }

        match ($method) {
            'arguments' => $this->arguments($entry, $call),
            'cron' => $this->stringField($entry, $call, 'cron'),
            'dailyat' => $this->dailyAt($entry, $call),
            'everyminute' => $entry['cron'] = '* * * * *',
            'hourly' => $entry['cron'] = '0 * * * *',
            'key' => $this->stringField($entry, $call, 'key'),
            'memorylimit' => $this->intField($entry, $call, 'memory_limit_megabytes'),
            'ononeserver' => $this->lockPolicy($entry, $call, 'on_one_server'),
            'timeout' => $this->floatField($entry, $call, 'timeout_seconds'),
            'timezone' => $this->stringField($entry, $call, 'timezone'),
            'withoutoverlap' => $this->lockPolicy($entry, $call, 'without_overlap'),
            default => $entry['dynamic_fields'][] = 'fluent:'.$method,
        };
    }

    /** @param ScheduleEntry $entry */
    private function arguments(array &$entry, Node\Expr\MethodCall $call): void
    {
        $expr = $this->callArg($call, 0, 'arguments');
        $array = $expr instanceof Node\Expr ? $this->values->literalArrayValue($expr) : null;
        $list = $this->values->stringList($array);
        if ($list === null) {
            $entry['arguments'] = null;
            $entry['dynamic_fields'][] = 'arguments';
            return;
        }
        $entry['arguments'] = $list;
    }

    /** @param ScheduleEntry $entry */
    private function dailyAt(array &$entry, Node\Expr\MethodCall $call): void
    {
        $expr = $this->callArg($call, 0, 'time');
        $time = $expr instanceof Node\Expr ? $this->values->stringValue($expr) : null;
        if ($time === null || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            $entry['cron'] = null;
            $entry['dynamic_fields'][] = 'cron';
            return;
        }
        [$hour, $minute] = explode(':', $time);
        $entry['cron'] = (int) $minute.' '.(int) $hour.' * * *';
    }

    /** @param ScheduleEntry $entry */
    private function stringField(array &$entry, Node\Expr\MethodCall $call, string $field): void
    {
        $name = match ($field) {
            'cron' => 'expression',
            'key' => 'key',
            'timezone' => 'timezone',
            default => $field,
        };
        $expr = $this->callArg($call, 0, $name);
        $value = $expr instanceof Node\Expr ? $this->values->stringValue($expr) : null;
        if ($value === null) {
            $entry[$field] = null;
            $entry['dynamic_fields'][] = $field;
            return;
        }
        $entry[$field] = $value;
    }

    /** @param ScheduleEntry $entry */
    private function intField(array &$entry, Node\Expr\MethodCall $call, string $field): void
    {
        $name = $field === 'memory_limit_megabytes' ? 'megabytes' : $field;
        $expr = $this->callArg($call, 0, $name);
        $value = $expr instanceof Node\Expr ? $this->literalNumber($expr) : null;
        if (!is_int($value)) {
            $entry[$field] = null;
            $entry['dynamic_fields'][] = $field;
            return;
        }
        $entry[$field] = $value;
    }

    /** @param ScheduleEntry $entry */
    private function floatField(array &$entry, Node\Expr\MethodCall $call, string $field): void
    {
        $expr = $this->callArg($call, 0, 'seconds');
        $value = $expr instanceof Node\Expr ? $this->literalNumber($expr) : null;
        if (!is_int($value) && !is_float($value)) {
            $entry[$field] = null;
            $entry['dynamic_fields'][] = $field;
            return;
        }
        $entry[$field] = (float) $value;
    }

    /** @param ScheduleEntry $entry */
    private function lockPolicy(array &$entry, Node\Expr\MethodCall $call, string $field): void
    {
        $enabledExpr = $this->callArg($call, 0, 'enabled');
        $leaseExpr = $this->callArg($call, 1, 'leaseSeconds');
        $waitExpr = $this->callArg($call, 2, 'waitSeconds');
        $enabled = $enabledExpr instanceof Node\Expr ? $this->literalBool($enabledExpr) : true;
        $lease = $leaseExpr instanceof Node\Expr ? $this->literalNumber($leaseExpr) : 300.0;
        $wait = $waitExpr instanceof Node\Expr ? $this->literalNumber($waitExpr) : 0.0;
        if ($enabled === null || $lease === null || $wait === null) {
            $entry[$field] = null;
            $entry['dynamic_fields'][] = $field;
            return;
        }
        $entry[$field] = $enabled;
        $entry['overlap_lease_seconds'] = (float) $lease;
        $entry['overlap_wait_seconds'] = (float) $wait;
    }

    /** @param ScheduleEntry $entry */
    private function finalize(array &$entry): void
    {
        $entry['dynamic_fields'] = array_values(array_unique($entry['dynamic_fields']));
        sort($entry['dynamic_fields'], SORT_STRING);
        if ($entry['key'] !== null) {
            $entry['identity'] = $entry['key'];
            $entry['identity_status'] = 'explicit_key';
        } elseif ($entry['command'] !== null && $entry['arguments'] !== null && $entry['cron'] !== null && $entry['timezone'] !== null) {
            $entry['identity'] = hash('sha256', json_encode([$entry['command'], $entry['arguments'], $entry['cron'], $entry['timezone']], JSON_THROW_ON_ERROR));
            $entry['identity_status'] = 'derived';
        }
        $entry['status'] = $entry['dynamic_fields'] === [] ? 'resolved' : 'dynamic';
    }

    private function callArg(Node\Expr\MethodCall $call, int $position, string $name): ?Node\Expr
    {
        return $this->values->arg($this->values->callArgs($call), $position, $name);
    }

    /** @return array{0:Node\Expr,1:list<Node\Expr\MethodCall>} */
    private function chain(Node\Expr\MethodCall $expr): array
    {
        $calls = [];
        $base = $expr;
        while ($base instanceof Node\Expr\MethodCall) {
            array_unshift($calls, $base);
            $base = $base->var;
        }
        return [$base, $calls];
    }

    private function literalBool(Node\Expr $expr): ?bool
    {
        if ($expr instanceof Node\Expr\ConstFetch) {
            return match (strtolower($expr->name->toString())) {
                'true' => true,
                'false' => false,
                default => null,
            };
        }
        return null;
    }

    private function literalNumber(Node\Expr $expr): int|float|null
    {
        return match (true) {
            $expr instanceof Node\Scalar\Int_ => $expr->value,
            $expr instanceof Node\Scalar\Float_ => $expr->value,
            $expr instanceof Node\Expr\UnaryMinus && $expr->expr instanceof Node\Scalar\Int_ => -$expr->expr->value,
            $expr instanceof Node\Expr\UnaryMinus && $expr->expr instanceof Node\Scalar\Float_ => -$expr->expr->value,
            $expr instanceof Node\Expr\UnaryPlus && $expr->expr instanceof Node\Scalar\Int_ => $expr->expr->value,
            $expr instanceof Node\Expr\UnaryPlus && $expr->expr instanceof Node\Scalar\Float_ => $expr->expr->value,
            default => null,
        };
    }

    private function isClass(Node\Name|Node\Expr $expr, string $class): bool
    {
        return $expr instanceof Node\Name && ltrim($this->values->resolvedName($expr), '\\') === $class;
    }

    /** @return list<Node\Stmt>|null */
    private function parse(string $path, string $source): ?array
    {
        $size = filesize($path);
        if ($size === false || $size > self::MAX_SOURCE_BYTES) {
            $this->diagnostic('source_too_large', $source, null, sprintf('Schedule source exceeds %d bytes.', self::MAX_SOURCE_BYTES));
            return null;
        }
        $contents = file_get_contents($path);
        if (!is_string($contents) || str_contains($contents, "\0")) {
            $this->diagnostic('source_unreadable', $source, null, 'Schedule source is unreadable or binary.');
            return null;
        }
        try {
            $nodes = $this->parser->parse($contents) ?? [];
        } catch (Error $error) {
            $this->diagnostic('parse_error', $source, $error->getStartLine(), $error->getMessage());
            return null;
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]));
        return $traverser->traverse($nodes);
    }

    /** @param list<Node\Stmt> $nodes */
    private function topLevelReturn(array $nodes): ?Node\Stmt\Return_
    {
        foreach ($nodes as $node) {
            foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $statement) {
                if ($statement instanceof Node\Stmt\Return_) {
                    return $statement;
                }
            }
        }
        return null;
    }

    private function limitDiagnostic(): void
    {
        if (!array_any($this->diagnostics, static fn(array $item): bool => $item['code'] === 'output_limit_exceeded')) {
            $this->diagnostic('output_limit_exceeded', null, null, sprintf('Schedule inspection is limited to %d entries.', self::MAX_ENTRIES));
        }
    }

    private function diagnostic(string $code, ?string $source, ?int $line, string $message): void
    {
        if (count($this->diagnostics) < self::MAX_DIAGNOSTICS) {
            $this->diagnostics[] = compact('code', 'source', 'line', 'message');
        }
    }
}
