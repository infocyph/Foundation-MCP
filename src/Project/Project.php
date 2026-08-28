<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Project;

final readonly class Project
{
    /**
     * @param array<string, mixed> $composer
     * @param array<string, mixed> $evidence
     */
    public function __construct(
        public string $root,
        public HostType $hostType,
        public array $composer,
        public array $evidence,
    ) {}

    public function composerPath(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'composer.json';
    }

    public function supported(): bool
    {
        return $this->hostType !== HostType::Unsupported;
    }
}
