<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Project;

use JsonException;

final class ProjectDetector
{
    private const array CANONICAL_DIRECTORIES = ['app', 'bootstrap', 'config', 'routes'];

    private const int JSON_DEPTH = 64;

    private const int MAX_COMPOSER_BYTES = 1_048_576;

    public function detect(string $root): Project
    {
        $composerPath = $root . DIRECTORY_SEPARATOR . 'composer.json';
        $canonicalDirectories = $this->canonicalDirectories($root);
        $infbyteExecutable = is_file($root . DIRECTORY_SEPARATOR . 'infbyte');
        $evidence = [
            'composer_json' => is_file($composerPath),
            'composer_name' => null,
            'foundation_constraint' => null,
            'infbyte_executable' => $infbyteExecutable,
            'foundation_bootstrap' => $this->bootstrapReferencesFoundation($root),
            'canonical_directories' => $canonicalDirectories,
        ];

        if (!is_file($composerPath)) {
            return new Project($root, HostType::Unsupported, [], $evidence);
        }

        try {
            $composer = json_decode(
                $this->readComposer($composerPath),
                true,
                self::JSON_DEPTH,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            $evidence['composer_json_valid'] = false;

            return new Project($root, HostType::Unsupported, [], $evidence);
        }

        if (!is_array($composer)) {
            $evidence['composer_json_valid'] = false;

            return new Project($root, HostType::Unsupported, [], $evidence);
        }

        $evidence['composer_json_valid'] = true;
        $evidence['composer_name'] = is_string($composer['name'] ?? null) ? $composer['name'] : null;

        $require = is_array($composer['require'] ?? null) ? $composer['require'] : [];
        $foundationConstraint = $require['infocyph/foundation'] ?? null;
        $evidence['foundation_constraint'] = is_string($foundationConstraint) ? $foundationConstraint : null;

        if (!is_string($foundationConstraint) || $foundationConstraint === '') {
            return new Project($root, HostType::Unsupported, $composer, $evidence);
        }

        $hostType = $infbyteExecutable && count($canonicalDirectories) >= 3
            ? HostType::Infbyte
            : HostType::FoundationCustom;

        return new Project($root, $hostType, $composer, $evidence);
    }

    private function bootstrapReferencesFoundation(string $root): bool
    {
        $path = $root . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';

        if (!is_file($path)) {
            return false;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $content = fread($handle, 65_536);
        } finally {
            fclose($handle);
        }

        if (!is_string($content)) {
            return false;
        }

        return str_contains($content, 'Infocyph\\Foundation\\Foundation')
            || preg_match('/\bFoundation::(?:web|cli|worker|scheduler)\s*\(/', $content) === 1;
    }

    /**
     * @return list<string>
     */
    private function canonicalDirectories(string $root): array
    {
        $directories = [];

        foreach (self::CANONICAL_DIRECTORIES as $directory) {
            if (is_dir($root . DIRECTORY_SEPARATOR . $directory)) {
                $directories[] = $directory;
            }
        }

        return $directories;
    }

    private function readComposer(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new JsonException('Unable to read composer.json.');
        }

        try {
            $content = stream_get_contents($handle, self::MAX_COMPOSER_BYTES + 1);
        } finally {
            fclose($handle);
        }

        if (!is_string($content)) {
            throw new JsonException('Unable to read composer.json.');
        }

        if (strlen($content) > self::MAX_COMPOSER_BYTES) {
            throw new JsonException('composer.json exceeds the 1 MiB metadata limit.');
        }

        return $content;
    }
}
