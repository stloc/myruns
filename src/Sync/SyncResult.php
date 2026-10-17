<?php

declare(strict_types=1);

namespace App\Sync;

use App\Import\ImportResult;

final class SyncResult
{
    public readonly ImportResult $import;

    /** Médiane de la FC de repos des 30 derniers jours, si intervals.icu la fournit. */
    public ?int $restingHeartRate = null;

    public function __construct(public readonly \DateTimeImmutable $since)
    {
        $this->import = new ImportResult();
    }

    public function summary(): string
    {
        $summary = \sprintf('Synchro depuis le %s : %s', $this->since->format('d/m/Y'), $this->import->summary());

        return null === $this->restingHeartRate ? $summary : $summary.\sprintf(' · FC de repos (médiane 30 j) : %d bpm', $this->restingHeartRate);
    }
}
