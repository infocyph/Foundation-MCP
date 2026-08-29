<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Composer;

final readonly class InstalledPackage
{
    /**
     * @param array<string, string> $require
     * @param array<string, mixed> $autoload
     * @param array<string, string> $suggest
     * @param array<string, string> $provide
     * @param array<string, string> $replace
     * @param array<string, string> $conflict
     */
    public function __construct(
        public string $name,
        public ?string $declaredConstraint,
        public ?string $declaredScope,
        public ?string $lockedVersion,
        public ?string $installedVersion,
        public ?string $lockedReference,
        public ?string $installedReference,
        public ?string $installPath,
        public bool $dev,
        public array $require,
        public array $autoload,
        public array $suggest,
        public array $provide,
        public array $replace,
        public array $conflict,
    ) {}

    public function direct(): bool
    {
        return $this->declaredScope !== null;
    }

    public function state(): string
    {
        if ($this->lockedVersion === null && $this->installedVersion === null) {
            return $this->direct() ? 'declared_unlocked' : 'unknown';
        }

        if ($this->lockedVersion !== null && $this->installedVersion === null) {
            return 'missing_install';
        }

        if ($this->lockedVersion === null) {
            return 'installed_unlocked';
        }

        if ($this->lockedVersion !== $this->installedVersion) {
            return 'version_mismatch';
        }

        if (
            $this->lockedReference !== null
            && $this->installedReference !== null
            && $this->lockedReference !== $this->installedReference
        ) {
            return 'source_reference_mismatch';
        }

        return 'matched';
    }
}
