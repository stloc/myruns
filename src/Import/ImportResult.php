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
}
