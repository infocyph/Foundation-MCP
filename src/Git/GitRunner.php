<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Git;

use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Security\SecretPolicy;
use RuntimeException;

/**
 * Minimal argument-vector Git boundary. Only fixed read-only operations are exposed.
 * No method accepts a Git ref and no shell command string is constructed.
 */
final readonly class GitRunner
{
    private const int MAX_OUTPUT_BYTES = 4_194_304;

    private SecretPolicy $secrets;

    public function __construct(
        private Project $project,
        private string $executable = 'git',
        private bool $enabled = true,
    ) {
        $this->secrets = new SecretPolicy();
    }

    public function available(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $result = $this->run(['--version'], allowFailure: true);

        return $result['exit'] === 0 && str_starts_with(trim($result['stdout']), 'git version ');
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    public function branch(): array
    {
        return $this->run(['symbolic-ref', '--quiet', '--short', 'HEAD'], allowFailure: true);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    public function head(): array
    {
        return $this->run(['rev-parse', '--verify', 'HEAD'], allowFailure: true);
    }

    public function headFile(string $path): ?string
    {
        $path = $this->safeRelativePath($path);
        $result = $this->run(['show', '--no-ext-diff', '--format=', 'HEAD:' . $path], allowFailure: true);

        return $result['exit'] === 0 ? $result['stdout'] : null;
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    public function status(): array
    {
        return $this->run(['-c', 'core.quotepath=false', 'status', '--porcelain=v1', '-z', '--untracked-files=all']);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function run(array $arguments, bool $allowFailure = false): array
    {
        if (!$this->enabled) {
            if ($allowFailure) {
                return ['exit' => 127, 'stdout' => '', 'stderr' => 'Git inspection is disabled.'];
            }

            throw new RuntimeException('Git inspection is disabled.');
        }

        $command = [$this->executable, '--no-optional-locks', '--no-pager', ...$arguments];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes, $this->project->root, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            if ($allowFailure) {
                return ['exit' => 127, 'stdout' => '', 'stderr' => 'Git is unavailable.'];
            }

            throw new RuntimeException('Git is unavailable.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], self::MAX_OUTPUT_BYTES + 1);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2], 65_537);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $stdout = is_string($stdout) ? $stdout : '';
        $stderr = is_string($stderr) ? $stderr : '';
        if (strlen($stdout) > self::MAX_OUTPUT_BYTES || strlen($stderr) > 65_536) {
            throw new RuntimeException('Git output exceeds the inspection limit.');
        }
        if (!$allowFailure && $exit !== 0) {
            throw new RuntimeException('Read-only Git inspection failed: ' . trim($stderr));
        }

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function safeRelativePath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('Invalid Git path.');
        }
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/^[A-Za-z]:\//', $path) === 1) {
            throw new RuntimeException('Git paths must be project-relative.');
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..' || strtolower($part) === '.git') {
                throw new RuntimeException('Git path traversal or metadata access is denied.');
            }
            $parts[] = $part;
        }
        if ($parts === []) {
            throw new RuntimeException('Invalid Git path.');
        }
        $normalized = implode('/', $parts);
        $this->secrets->assertAllowed($normalized);

        return $normalized;
    }
}
