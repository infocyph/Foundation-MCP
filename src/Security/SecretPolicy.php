<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Security;

use RuntimeException;

final class SecretPolicy
{
    private const array SECRET_BASENAMES = [
        '.git-credentials',
        '.netrc',
        'auth.json',
        'credential',
        'credentials',
        'id_dsa',
        'id_ecdsa',
        'id_ed25519',
        'id_rsa',
        'service-account.json',
        'service_account.json',
    ];

    private const array SECRET_EXTENSIONS = ['key', 'p12', 'pfx', 'pem', 'ppk'];

    public function assertAllowed(string $path): void
    {
        if ($this->denied($path)) {
            throw new RuntimeException('Secret-bearing resource is denied.');
        }
    }

    public function denied(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return true;
        }

        $basename = strtolower(basename(str_replace('\\', '/', $path)));

        if ($basename === '.env.example') {
            return false;
        }

        if ($basename === '.env' || str_starts_with($basename, '.env.')) {
            return true;
        }

        if (in_array($basename, self::SECRET_BASENAMES, true)) {
            return true;
        }

        if (preg_match('/^credentials?\./', $basename) === 1) {
            return true;
        }

        $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

        return in_array($extension, self::SECRET_EXTENSIONS, true);
    }
}
