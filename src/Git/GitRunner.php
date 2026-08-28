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

    private const int MAX_STDERR_BYTES = 65_536;

    private const int TIMEOUT_NANOSECONDS = 5_000_000_000;

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
        return $this->run([
            '-c',
            'core.quotepath=false',
            'status',
            '--porcelain=v1',
            '-z',
            '--untracked-files=all',
            '--ignore-submodules=all',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];

        foreach (array_keys($environment) as $name) {
            if (str_starts_with(strtoupper($name), 'GIT_')) {
                unset($environment[$name]);
            }
        }

        // Ignore caller/global Git redirection and interactive helpers; only the resolved project repository should influence inspection.
        $environment['GIT_CONFIG_NOSYSTEM'] = '1';
        $environment['GIT_CONFIG_GLOBAL'] = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $environment['GIT_OPTIONAL_LOCKS'] = '0';
        $environment['GIT_TERMINAL_PROMPT'] = '0';

        return $environment;
    }

    /** @param resource $pipe */
    private function readPipe($pipe, int $remaining): string
    {
        $chunk = stream_get_contents($pipe, max(0, $remaining) + 1);

        return is_string($chunk) ? $chunk : '';
    }

    /**
     * @param list<string> $arguments
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function run(array $arguments, bool $allowFailure = false): array
    {
        if (!$this->enabled) {
            if ($allowFailure) {
                return ['exit' => 127, 'stdout' => '', 'stderr' => 'Git inspection is disabled.'];
            }

            throw new RuntimeException('Git inspection is disabled.');
        }

        $command = [
            $this->executable,
            '--no-optional-locks',
            '--no-pager',
            '-c',
            'core.fsmonitor=false',
            ...$arguments,
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // proc_open warns when Git is unavailable; normalize that boundary failure without leaking a warning into the MCP host.
        set_error_handler(static fn(int $severity): bool => $severity === E_WARNING, E_WARNING);

        try {
            $process = proc_open(
                $command,
                $descriptors,
                $pipes,
                $this->project->root,
                $this->environment(),
                ['bypass_shell' => true],
            );
        } finally {
            restore_error_handler();
        }

        if (!is_resource($process)) {
            if ($allowFailure) {
                return ['exit' => 127, 'stdout' => '', 'stderr' => 'Git is unavailable.'];
            }

            throw new RuntimeException('Git is unavailable.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $exit = null;
        $deadline = hrtime(true) + self::TIMEOUT_NANOSECONDS;

        try {
            while (true) {
                $stdout .= $this->readPipe($pipes[1], self::MAX_OUTPUT_BYTES - strlen($stdout));
                $stderr .= $this->readPipe($pipes[2], self::MAX_STDERR_BYTES - strlen($stderr));

                if (strlen($stdout) > self::MAX_OUTPUT_BYTES || strlen($stderr) > self::MAX_STDERR_BYTES) {
                    throw new RuntimeException('Git output exceeds the inspection limit.');
                }

                $status = proc_get_status($process);

                if (!$status['running']) {
                    $exit = is_int($status['exitcode']) ? $status['exitcode'] : -1;
                    $stdout .= $this->readPipe($pipes[1], self::MAX_OUTPUT_BYTES - strlen($stdout));
                    $stderr .= $this->readPipe($pipes[2], self::MAX_STDERR_BYTES - strlen($stderr));

                    if (strlen($stdout) > self::MAX_OUTPUT_BYTES || strlen($stderr) > self::MAX_STDERR_BYTES) {
                        throw new RuntimeException('Git output exceeds the inspection limit.');
                    }

                    break;
                }

                if (hrtime(true) >= $deadline) {
                    throw new RuntimeException('Git inspection exceeded the 5 second execution limit.');
                }

                usleep(1_000);
            }
        } finally {
            $status = proc_get_status($process);

            if ($status['running']) {
                proc_terminate($process);
                usleep(10_000);
                $status = proc_get_status($process);

                if ($status['running']) {
                    proc_terminate($process, 9);
                }
            }

            fclose($pipes[1]);
            fclose($pipes[2]);
            $closedExit = proc_close($process);

            if ($exit === null || $exit < 0) {
                $exit = $closedExit;
            }
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
