<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Git;

use Infocyph\FoundationMcp\Analysis\PhpAnalyzer;
use Infocyph\FoundationMcp\Analysis\TestLocator;
use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Project\Project;
use RuntimeException;

/**
 * @phpstan-type FileChange array{path:string,original_path:?string,index_status:string,worktree_status:string,staged:bool,unstaged:bool,untracked:bool,change:string,areas:list<string>}
 * @phpstan-type SymbolDelta array{added:list<string>,removed:list<string>,modified:list<string>}
 * @phpstan-type ReferenceDelta array{added:list<array{relationship:string,target:string,confidence:string}>,removed:list<array{relationship:string,target:string,confidence:string}>}
 */
final class WorkspaceInspector
{
    private const int MAX_FILES = 500;
    private const int MAX_PHP_FILES = 200;
    private const int MAX_TESTS = 100;

    private readonly GitRunner $git;
    private readonly PhpAnalyzer $analyzer;
    private readonly TestLocator $tests;

    public function __construct(
        private readonly Project $project,
        ComposerInspector $composer,
        ?GitRunner $git = null,
        ?PhpAnalyzer $analyzer = null,
        ?TestLocator $tests = null,
    ) {
        $this->git = $git ?? new GitRunner($project);
        $this->analyzer = $analyzer ?? new PhpAnalyzer($project, $composer);
        $this->tests = $tests ?? new TestLocator($project, $composer);
    }

    /**
     * @return array{
     *   available:bool,head:?string,branch:?string,detached:bool,dirty:bool,
     *   files:list<FileChange>,php_changes:list<array{path:string,original_path:?string,declarations:SymbolDelta,references:ReferenceDelta,errors:list<array<string,mixed>>}>,
     *   changed_symbols:list<string>,affected_tests:list<string>,areas:list<string>,composer_changed:bool,diagnostics:list<array{code:string,message:string}>
     * }
     */
    public function inspect(): array
    {
        if (!$this->git->available()) {
            return $this->emptyResult([['code' => 'git_unavailable', 'message' => 'Git is unavailable for this project.']]);
        }

        try {
            $status = $this->git->status();
        } catch (RuntimeException $error) {
            return $this->emptyResult([['code' => 'git_unavailable', 'message' => $error->getMessage()]]);
        }

        $files = $this->parseStatus($status['stdout']);
        $diagnostics = [];
        if (count($files) > self::MAX_FILES) {
            $files = array_slice($files, 0, self::MAX_FILES);
            $diagnostics[] = ['code' => 'output_limit_exceeded', 'message' => sprintf('Git workspace inspection is limited to %d changed files.', self::MAX_FILES)];
        }

        $headResult = $this->git->head();
        $branchResult = $this->git->branch();
        $head = $headResult['exit'] === 0 ? trim($headResult['stdout']) : null;
        $branch = $branchResult['exit'] === 0 ? trim($branchResult['stdout']) : null;
        $detached = $head !== null && $branch === null;

        $phpChanges = [];
        $changedSymbols = [];
        $affectedTests = [];
        $phpCount = 0;
        foreach ($files as $file) {
            if (strtolower(pathinfo($file['path'], PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }
            if (++$phpCount > self::MAX_PHP_FILES) {
                $diagnostics[] = ['code' => 'output_limit_exceeded', 'message' => sprintf('PHP change analysis is limited to %d changed PHP files.', self::MAX_PHP_FILES)];
                break;
            }
            $delta = $this->phpDelta($file);
            $phpChanges[] = $delta;
            foreach ([...$delta['declarations']['added'], ...$delta['declarations']['removed'], ...$delta['declarations']['modified']] as $symbol) {
                $changedSymbols[$symbol] = true;
            }
            if ($file['change'] !== 'deleted' && is_file($this->project->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file['path']))) {
                try {
                    foreach ($this->tests->forFile($file['path'], 20) as $test) {
                        $affectedTests[$test['path']] = true;
                        if (count($affectedTests) >= self::MAX_TESTS) {
                            break;
                        }
                    }
                } catch (RuntimeException) {
                    // Broken/ambiguous changed source must not break Git summary.
                }
            }
        }

        $symbols = array_keys($changedSymbols);
        sort($symbols, SORT_STRING);
        $testPaths = array_keys($affectedTests);
        sort($testPaths, SORT_STRING);
        $areas = [];
        foreach ($files as $file) {
            foreach ($file['areas'] as $area) {
                $areas[$area] = true;
            }
        }
        ksort($areas, SORT_STRING);

        return [
            'available' => true,
            'head' => $head,
            'branch' => $branch,
            'detached' => $detached,
            'dirty' => $files !== [],
            'files' => $files,
            'php_changes' => $phpChanges,
            'changed_symbols' => $symbols,
            'affected_tests' => $testPaths,
            'areas' => array_keys($areas),
            'composer_changed' => array_any($files, static fn (array $file): bool => in_array($file['path'], ['composer.json', 'composer.lock'], true)),
            'diagnostics' => $diagnostics,
        ];
    }

    /** @return list<FileChange> */
    private function parseStatus(string $status): array
    {
        if ($status === '') {
            return [];
        }
        $parts = explode("\0", $status);
        $files = [];
        for ($index = 0, $count = count($parts); $index < $count; ++$index) {
            $entry = $parts[$index];
            if ($entry === '') {
                continue;
            }
            $x = $entry[0] ?? ' ';
            $y = $entry[1] ?? ' ';
            $path = substr($entry, 3);
            if ($path === '') {
                continue;
            }
            $original = null;
            if (in_array($x, ['R', 'C'], true) || in_array($y, ['R', 'C'], true)) {
                $original = $parts[++$index] ?? null;
            }
            $files[] = [
                'path' => str_replace('\\', '/', $path),
                'original_path' => is_string($original) && $original !== '' ? str_replace('\\', '/', $original) : null,
                'index_status' => $x,
                'worktree_status' => $y,
                'staged' => !in_array($x, [' ', '?', '!'], true),
                'unstaged' => !in_array($y, [' ', '?', '!'], true),
                'untracked' => $x === '?' && $y === '?',
                'change' => $this->changeType($x, $y),
                'areas' => $this->areas($path),
            ];
        }
        usort($files, static fn (array $left, array $right): int => [$left['path'], $left['original_path'] ?? ''] <=> [$right['path'], $right['original_path'] ?? '']);
        return $files;
    }

    /** @param FileChange $file */
    private function phpDelta(array $file): array
    {
        $current = null;
        $baseline = null;
        $errors = [];
        if ($file['change'] !== 'deleted') {
            try {
                $current = $this->analyzer->project($file['path']);
                array_push($errors, ...$current->errors);
            } catch (RuntimeException $error) {
                $errors[] = ['code' => 'internal_analysis_failure', 'message' => $error->getMessage(), 'line' => null];
            }
        }
        $oldPath = $file['original_path'] ?? $file['path'];
        $oldSource = $this->git->headFile($oldPath);
        if ($oldSource !== null) {
            try {
                $baseline = $this->analyzer->text($oldPath, $oldSource, 'git_head');
                array_push($errors, ...$baseline->errors);
            } catch (RuntimeException $error) {
                $errors[] = ['code' => 'internal_analysis_failure', 'message' => $error->getMessage(), 'line' => null];
            }
        }

        return [
            'path' => $file['path'],
            'original_path' => $file['original_path'],
            'declarations' => $this->declarationDelta($baseline?->declarations ?? [], $current?->declarations ?? []),
            'references' => $this->referenceDelta($baseline?->references ?? [], $current?->references ?? []),
            'errors' => array_slice($errors, 0, 20),
        ];
    }

    /** @param list<array<string,mixed>> $old @param list<array<string,mixed>> $new @return SymbolDelta */
    private function declarationDelta(array $old, array $new): array
    {
        $oldMap = $this->declarationMap($old);
        $newMap = $this->declarationMap($new);
        $added = [];
        $removed = [];
        $modified = [];
        foreach ($newMap as $key => $item) {
            if (!isset($oldMap[$key])) {
                $added[] = $item['symbol'];
            } elseif ($oldMap[$key]['fingerprint'] !== $item['fingerprint']) {
                $modified[] = $item['symbol'];
            }
        }
        foreach ($oldMap as $key => $item) {
            if (!isset($newMap[$key])) {
                $removed[] = $item['symbol'];
            }
        }
        foreach ([$added, $removed, $modified] as &$items) {
            sort($items, SORT_STRING);
        }
        unset($items);
        return compact('added', 'removed', 'modified');
    }

    /** @param list<array<string,mixed>> $items @return array<string,array{symbol:string,fingerprint:string}> */
    private function declarationMap(array $items): array
    {
        $map = [];
        foreach ($items as $item) {
            $symbol = (string) ($item['symbol'] ?? '');
            $kind = (string) ($item['kind'] ?? '');
            if ($symbol === '' || $kind === '') {
                continue;
            }
            $normalized = $item;
            unset($normalized['line'], $normalized['end_line']);
            $map[$kind."\0".$symbol] = ['symbol' => $symbol, 'fingerprint' => hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR))];
        }
        return $map;
    }

    /** @param list<array<string,mixed>> $old @param list<array<string,mixed>> $new @return ReferenceDelta */
    private function referenceDelta(array $old, array $new): array
    {
        $oldMap = $this->referenceMap($old);
        $newMap = $this->referenceMap($new);
        $added = [];
        $removed = [];
        foreach ($newMap as $key => $item) {
            if (!isset($oldMap[$key])) {
                $added[] = $item;
            }
        }
        foreach ($oldMap as $key => $item) {
            if (!isset($newMap[$key])) {
                $removed[] = $item;
            }
        }
        $sort = static fn (array $left, array $right): int => [$left['relationship'], $left['target']] <=> [$right['relationship'], $right['target']];
        usort($added, $sort);
        usort($removed, $sort);
        return compact('added', 'removed');
    }

    /** @param list<array<string,mixed>> $items @return array<string,array{relationship:string,target:string,confidence:string}> */
    private function referenceMap(array $items): array
    {
        $map = [];
        foreach ($items as $item) {
            $relationship = (string) ($item['relationship'] ?? '');
            $target = (string) ($item['target'] ?? '');
            if ($relationship === '' || $target === '') {
                continue;
            }
            $key = $relationship."\0".$target;
            $map[$key] = ['relationship' => $relationship, 'target' => $target, 'confidence' => (string) ($item['confidence'] ?? 'lexical')];
        }
        return $map;
    }

    private function changeType(string $x, string $y): string
    {
        if ($x === '?' && $y === '?') {
            return 'untracked';
        }
        foreach ([$x, $y] as $status) {
            $type = match ($status) {
                'A' => 'added',
                'D' => 'deleted',
                'R' => 'renamed',
                'C' => 'copied',
                'T' => 'type_changed',
                'U' => 'unmerged',
                'M' => 'modified',
                default => null,
            };
            if ($type !== null) {
                return $type;
            }
        }
        return 'unknown';
    }

    /** @return list<string> */
    private function areas(string $path): array
    {
        $path = str_replace('\\', '/', $path);
        $areas = [];
        if (in_array($path, ['composer.json', 'composer.lock'], true)) {
            $areas[] = 'composer';
        }
        if ($path === 'bootstrap/app.php') {
            $areas[] = 'runtime';
        }
        if ($path === 'bootstrap/providers.php') {
            $areas[] = 'provider';
        }
        if ($path === 'routes/console.php') {
            $areas[] = 'command';
        }
        if ($path === 'routes/schedule.php') {
            $areas[] = 'schedule';
        }
        if ($path === 'routes/workers.php') {
            $areas[] = 'worker';
        }
        if (str_starts_with($path, 'routes/') && !in_array($path, ['routes/console.php', 'routes/schedule.php', 'routes/workers.php'], true)) {
            $areas[] = 'route';
        }
        if (str_starts_with($path, 'config/')) {
            $areas[] = 'config';
        }
        if (str_ends_with($path, '/ModuleCatalog.php') || $path === 'src/Module/ModuleCatalog.php') {
            $areas[] = 'module';
        }
        sort($areas, SORT_STRING);
        return $areas;
    }

    /** @param list<array{code:string,message:string}> $diagnostics */
    private function emptyResult(array $diagnostics): array
    {
        return [
            'available' => false,
            'head' => null,
            'branch' => null,
            'detached' => false,
            'dirty' => false,
            'files' => [],
            'php_changes' => [],
            'changed_symbols' => [],
            'affected_tests' => [],
            'areas' => [],
            'composer_changed' => false,
            'diagnostics' => $diagnostics,
        ];
    }
}
