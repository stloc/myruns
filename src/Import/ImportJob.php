<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Import lancé depuis la page web, mené en plusieurs requêtes (voir ImportJobs) : la 1ʳᵉ lecture
 * d'une archive complète prend plusieurs minutes (appels IGN pour chaque trace).
 */
final class ImportJob
{
    public function __construct(
        public readonly string $id,
        /** Archive, dossier ou trace à importer. */
        public readonly string $path,
        /** Fichier envoyé par le formulaire : supprimé une fois l'import terminé ou abandonné. */
        public readonly bool $temporary,
        public readonly \DateTimeImmutable $startedAt,
        /** Nombre de traces, connu après la lecture du CSV (1ʳᵉ étape). */
        public ?int $total = null,
        /** Traces déjà lues. */
        public int $done = 0,
        public ImportResult $result = new ImportResult(),
    ) {
    }

    public function isFinished(): bool
    {
        return null !== $this->total && $this->done >= $this->total;
    }

    public function percent(): int
    {
        return null === $this->total || 0 === $this->total ? 0 : (int) floor(100 * $this->done / $this->total);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            'temporary' => $this->temporary,
            'startedAt' => $this->startedAt->format(\DATE_ATOM),
            'total' => $this->total,
            'done' => $this->done,
            'result' => $this->result->toArray(),
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $startedAt = \is_string($data['startedAt'] ?? null) ? \DateTimeImmutable::createFromFormat(\DATE_ATOM, $data['startedAt']) : false;
        if (!\is_string($data['id'] ?? null) || !\is_string($data['path'] ?? null) || false === $startedAt) {
            return null;
        }

        return new self(
            $data['id'],
            $data['path'],
            true === ($data['temporary'] ?? null),
            $startedAt,
            \is_int($data['total'] ?? null) ? $data['total'] : null,
            \is_int($data['done'] ?? null) ? $data['done'] : 0,
            ImportResult::fromArray(\is_array($data['result'] ?? null) ? $data['result'] : []),
        );
    }
}
