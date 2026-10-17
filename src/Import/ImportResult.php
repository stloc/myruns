<?php

declare(strict_types=1);

namespace App\Import;

final class ImportResult
{
    public int $created = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $ignored = 0;
    /** Activités dont les temps de passage ont été (re)calculés depuis une trace. */
    public int $withSplits = 0;
    /** Traces dont le D+ a été recalculé sur l'altitude du terrain (IGN, Open-Meteo hors de France). */
    public int $groundElevation = 0;
    /** Traces GPS dont l'altitude du terrain n'a pas pu être obtenue (service injoignable). */
    public int $groundElevationMissing = 0;

    /** @var list<string> */
    public array $errors = [];

    public function summary(): string
    {
        $summary = \sprintf('%d nouvelle(s), %d mise(s) à jour, %d déjà connue(s), %d temps de passage calculé(s), %d fichier(s) ignoré(s)', $this->created, $this->updated, $this->unchanged, $this->withSplits, $this->ignored);

        if ($this->groundElevationMissing > 0) {
            $summary .= \sprintf(', D+ du terrain indisponible pour %d trace(s) (service d\'altitude injoignable : relancer l\'import plus tard)', $this->groundElevationMissing);
        }

        return [] === $this->errors ? $summary : $summary.\sprintf(', %d erreur(s)', \count($this->errors));
    }

    /**
     * Compteurs gardés entre les étapes d'un import web (voir ImportJob).
     *
     * @return array<string, int|list<string>>
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'ignored' => $this->ignored,
            'withSplits' => $this->withSplits,
            'groundElevation' => $this->groundElevation,
            'groundElevationMissing' => $this->groundElevationMissing,
            'errors' => $this->errors,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $int = static fn (string $key): int => \is_int($data[$key] ?? null) ? $data[$key] : 0;
        $result = new self();
        $result->created = $int('created');
        $result->updated = $int('updated');
        $result->unchanged = $int('unchanged');
        $result->ignored = $int('ignored');
        $result->withSplits = $int('withSplits');
        $result->groundElevation = $int('groundElevation');
        $result->groundElevationMissing = $int('groundElevationMissing');
        $errors = \is_array($data['errors'] ?? null) ? $data['errors'] : [];
        $result->errors = array_values(array_filter($errors, is_string(...)));

        return $result;
    }
}
