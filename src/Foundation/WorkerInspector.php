<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Foundation;

/** Keeps Foundation maintenance workers and Omnibus messaging workers explicit. */
final readonly class WorkerInspector
{
    public function __construct(
        private FoundationWorkerInspector $foundation,
        private OmnibusWorkerInspector $omnibus,
    ) {}

    /** @return array{foundation_workers:array,omnibus_workers:array} */
    public function inspect(): array
    {
        return [
            'foundation_workers' => $this->foundation->inspect(),
            'omnibus_workers' => $this->omnibus->inspect(),
        ];
    }
}
