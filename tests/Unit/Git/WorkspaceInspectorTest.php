<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Git\GitRunner;
use Infocyph\FoundationMcp\Git\WorkspaceInspector;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;

it('reports bounded staged unstaged untracked rename and PHP API deltas without mutating Git', function (): void {
    $root = TempProject::create(
        composer: [
            'require' => ['infocyph/foundation' => '^2.1'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
        ],
        directories: ['app', 'tests', 'config'],
        files: [
            'app/Service.php' => "<?php\nnamespace App;\nfinal class Service { public function oldMethod(): void {} }\n",
            'app/Legacy.php' => "<?php\nnamespace App;\nfinal class Legacy {}\n",
            'tests/ServiceTest.php' => "<?php\nnamespace Tests;\nuse App\\Service;\nfinal class ServiceTest { public function testIt(): void { new Service(); } }\n",
        ],
    );

    try {
        workspaceGit($root, ['init', '-q']);
        workspaceGit($root, ['config', 'user.email', 'test@example.com']);
        workspaceGit($root, ['config', 'user.name', 'Foundation MCP Test']);
        workspaceGit($root, ['add', '.']);
        workspaceGit($root, ['commit', '-qm', 'baseline']);

        file_put_contents($root.'/app/Service.php', "<?php\nnamespace App;\nfinal class Service { public function newMethod(): void {} }\n");
        workspaceGit($root, ['mv', 'app/Legacy.php', 'app/Renamed.php']);
        file_put_contents($root.'/config/app.php', "<?php return ['debug' => true];\n");
        $indexBefore = hash_file('sha256', $root.'/.git/index');

        $project = (new ProjectDetector())->detect($root);
        $composer = new ComposerInspector($project);
        $result = (new WorkspaceInspector($project, $composer))->inspect();
        $indexAfter = hash_file('sha256', $root.'/.git/index');

        expect($result['available'])->toBeTrue()
            ->and($result['dirty'])->toBeTrue()
            ->and($result['head'])->not->toBeNull()
            ->and($result['branch'])->not->toBeNull()
            ->and(workspaceChangedFile($result['files'], 'app/Service.php'))->toMatchArray(['unstaged' => true, 'change' => 'modified'])
            ->and(workspaceChangedFile($result['files'], 'app/Renamed.php'))->toMatchArray(['staged' => true, 'change' => 'renamed', 'original_path' => 'app/Legacy.php'])
            ->and(workspaceChangedFile($result['files'], 'config/app.php'))->toMatchArray(['untracked' => true, 'change' => 'untracked'])
            ->and($result['changed_symbols'])->toContain('App\\Service::oldMethod', 'App\\Service::newMethod')
            ->and($result['affected_tests'])->toContain('tests/ServiceTest.php')
            ->and($result['areas'])->toContain('config')
            ->and($result['composer_changed'])->toBeFalse()
            ->and($indexAfter)->toBe($indexBefore);

        expect(fn () => (new GitRunner($project))->headFile('../outside.php'))->toThrow(RuntimeException::class)
            ->and((new GitRunner($project, enabled: false))->available())->toBeFalse();
    } finally {
        TempProject::remove($root);
    }
});

/** @param list<array<string,mixed>> $files */
function workspaceChangedFile(array $files, string $path): ?array
{
    foreach ($files as $file) {
        if ($file['path'] === $path) {
            return $file;
        }
    }
    return null;
}

/** @param list<string> $args */
function workspaceGit(string $root, array $args): string
{
    $process = proc_open(['git', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Git is required for this test.');
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException('Git test command failed: '.$err);
    }
    return $out;
}
