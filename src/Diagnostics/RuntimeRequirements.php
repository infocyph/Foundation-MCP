<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Diagnostics;

use Composer\InstalledVersions;
use PhpParser\ParserFactory;
use RuntimeException;
use Throwable;

final class RuntimeRequirements
{
    public static function assertAvailable(): void
    {
        $phpForge = self::phpForgeCheck();
        if (!$phpForge['ok']) {
            throw new RuntimeException('phpforge_unavailable: ' . $phpForge['detail']);
        }

        $parser = self::parserCheck();
        if (!$parser['ok']) {
            throw new RuntimeException('analysis_backend_unavailable: ' . $parser['detail']);
        }
    }

    /** @return array{name:string,ok:bool,detail:string} */
    public static function parserCheck(): array
    {
        if (!class_exists(ParserFactory::class)) {
            return [
                'name' => 'Parser capability',
                'ok' => false,
                'detail' => ParserFactory::class . ' unavailable; install the required PHPForge development toolchain',
            ];
        }

        try {
            $parser = new ParserFactory()->createForNewestSupportedVersion();
            $nodes = $parser->parse('<?php final class FoundationMcpParserProbe {}');
            $ok = is_array($nodes) && $nodes !== [];
        } catch (Throwable $exception) {
            return [
                'name' => 'Parser capability',
                'ok' => false,
                'detail' => 'Parser compatibility failure: ' . $exception->getMessage(),
            ];
        }

        return [
            'name' => 'Parser capability',
            'ok' => $ok,
            'detail' => $ok ? 'parse probe passed' : 'parse probe returned no nodes',
        ];
    }

    /** @return array{name:string,ok:bool,detail:string} */
    public static function phpForgeCheck(): array
    {
        try {
            $installed = InstalledVersions::isInstalled('infocyph/phpforge');
            $version = $installed
                ? InstalledVersions::getPrettyVersion('infocyph/phpforge')
                    ?? InstalledVersions::getVersion('infocyph/phpforge')
                    ?? 'installed'
                : 'not installed';
        } catch (Throwable $exception) {
            return [
                'name' => 'PHPForge',
                'ok' => false,
                'detail' => 'Composer metadata unavailable: ' . $exception->getMessage(),
            ];
        }

        return [
            'name' => 'PHPForge',
            'ok' => $installed,
            'detail' => $installed
                ? $version
                : 'not installed; require infocyph/phpforge:dev-main@dev under the host require-dev section',
        ];
    }
}
