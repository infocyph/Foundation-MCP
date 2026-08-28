<?php

declare(strict_types=1);

it('keeps production source free of host execution network mutation and arbitrary shell primitives', function (): void {
    $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src';
    $files = safetyPhpFiles($root);
    $forbiddenFunctions = [
        'assert', 'copy', 'curl_exec', 'curl_init', 'dns_get_record', 'exec', 'fsockopen',
        'gethostbyname', 'gethostbynamel', 'link', 'mkdir', 'pfsockopen', 'popen', 'rename',
        'rmdir', 'shell_exec', 'socket_create', 'stream_socket_client', 'symlink', 'system', 'passthru',
        'touch', 'unlink', 'file_put_contents', 'chmod', 'chown', 'chgrp',
    ];
    $executionTokens = [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE];
    $procOpenFiles = [];

    foreach ($files as $file) {
        $source = file_get_contents($file);
        expect($source)->toBeString();
        $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
        $tokens = token_get_all($source);

        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }

            expect($executionTokens)->not->toContain($token[0], "Host/source execution token found in {$relative}");
            if ($token[0] !== T_STRING) {
                continue;
            }

            $name = strtolower($token[1]);
            if (!safetyIsFunctionCall($tokens, $index)) {
                continue;
            }

            expect($forbiddenFunctions)->not->toContain($name, "Forbidden operation {$name}() found in {$relative}");
            if ($name === 'proc_open') {
                $procOpenFiles[] = $relative;
            }
        }

        expect($source)->not->toContain('http://')
            ->and($source)->not->toContain('https://')
            ->and($source)->not->toContain('GuzzleHttp\\')
            ->and($source)->not->toContain('Symfony\\Contracts\\HttpClient')
            ->and($source)->not->toContain('SoapClient');
    }

    expect($procOpenFiles)->toBe(['Git/GitRunner.php']);
    $git = file_get_contents($root.DIRECTORY_SEPARATOR.'Git'.DIRECTORY_SEPARATOR.'GitRunner.php');
    expect($git)->toContain("['bypass_shell' => true]")
        ->and($git)->toContain("'--no-optional-locks'")
        ->and($git)->not->toContain('sh -c')
        ->and($git)->not->toContain('bash -c')
        ->and($git)->not->toContain('cmd /c')
        ->and($git)->not->toContain('powershell');
});

/** @return list<string> */
function safetyPhpFiles(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files, SORT_STRING);
    return $files;
}

/** @param list<array{0:int,1:string,2:int}|string> $tokens */
function safetyIsFunctionCall(array $tokens, int $index): bool
{
    for ($next = $index + 1, $count = count($tokens); $next < $count; ++$next) {
        $token = $tokens[$next];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return $token === '(';
    }
    return false;
}
