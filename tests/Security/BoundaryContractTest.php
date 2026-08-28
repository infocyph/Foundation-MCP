<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Git\GitRunner;
use Infocyph\FoundationMcp\Mcp\OutputBudget;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Resource\ResourceReader;
use Infocyph\FoundationMcp\Tests\Support\TempProject;

it('rejects secret traversal binary oversized output and shell-shaped Git inputs', function (): void {
    $root = TempProject::create(
        composer: [
            'require' => ['infocyph/foundation' => '^2.1'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ],
        directories: ['app'],
        files: [
            'README.md' => "safe\n",
            '.env' => "APP_KEY=super-secret\n",
            'binary.bin' => "abc\0def",
            'large.txt' => str_repeat('x', 1_048_577),
        ],
    );

    try {
        $project = (new ProjectDetector())->detect($root);
        $reader = new ResourceReader($project, new ComposerInspector($project));

        expect(fn () => $reader->project('.env'))->toThrow(RuntimeException::class)
            ->and(fn () => $reader->project('../outside.txt'))->toThrow(RuntimeException::class)
            ->and(fn () => $reader->project('binary.bin'))->toThrow(RuntimeException::class)
            ->and(fn () => $reader->project('large.txt'))->toThrow(RuntimeException::class)
            ->and(fn () => (new OutputBudget())->tool(['payload' => str_repeat('x', 1_048_577)]))->toThrow(RuntimeException::class);

        securityGit($root, ['init', '-q']);
        securityGit($root, ['config', 'user.email', 'security@example.com']);
        securityGit($root, ['config', 'user.name', 'Security Test']);
        securityGit($root, ['add', 'README.md']);
        securityGit($root, ['commit', '-qm', 'baseline']);

        $runner = new GitRunner($project);
        expect($runner->headFile('README.md;touch PWNED'))->toBeNull()
            ->and(file_exists($root.'/PWNED'))->toBeFalse();
    } finally {
        TempProject::remove($root);
    }
});

/** @param list<string> $args */
function securityGit(string $root, array $args): void
{
    $process = proc_open(['git', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Git is required for the security contract test.');
    }

    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[2]);
    $exit = proc_close($process);

    if ($exit !== 0) {
        throw new RuntimeException('Security test Git command failed: '.$stderr);
    }
}
