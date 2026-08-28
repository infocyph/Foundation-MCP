<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Diagnostics;

use Composer\InstalledVersions;
use PhpParser\ParserFactory;
use Throwable;

final class Doctor
{
    /**
     * @return list<array{name: string, ok: bool, detail: string}>
     */
    public function checks(): array
    {
        return [
            $this->phpCheck(),
            $this->packageCheck('mcp/sdk', 'Official MCP SDK'),
            $this->packageCheck('infocyph/phpforge', 'PHPForge'),
            $this->packageCheck('nikic/php-parser', 'PHP parser backend'),
            $this->parserCheck(),
        ];
    }

    public function run(): int
    {
        $failed = false;

        foreach ($this->checks() as $check) {
            $failed = $failed || !$check['ok'];
            fwrite(
                STDERR,
                sprintf(
                    "[%s] %s: %s%s",
                    $check['ok'] ? 'OK' : 'FAIL',
                    $check['name'],
                    $check['detail'],
                    PHP_EOL,
                ),
            );
        }

        return $failed ? 1 : 0;
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function phpCheck(): array
    {
        $ok = PHP_VERSION_ID >= 80400;

        return [
            'name' => 'PHP',
            'ok' => $ok,
            'detail' => PHP_VERSION.($ok ? '' : ' (8.4+ required)'),
        ];
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function packageCheck(string $package, string $label): array
    {
        try {
            $installed = InstalledVersions::isInstalled($package);
            $version = $installed
                ? InstalledVersions::getPrettyVersion($package) ?? InstalledVersions::getVersion($package) ?? 'installed'
                : 'not installed';
        } catch (Throwable $exception) {
            return [
                'name' => $label,
                'ok' => false,
                'detail' => 'Composer metadata unavailable: '.$exception->getMessage(),
            ];
        }

        return [
            'name' => $label,
            'ok' => $installed,
            'detail' => $version,
        ];
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function parserCheck(): array
    {
        $ok = class_exists(ParserFactory::class);

        return [
            'name' => 'Parser capability',
            'ok' => $ok,
            'detail' => $ok ? ParserFactory::class : ParserFactory::class.' unavailable',
        ];
    }
}
