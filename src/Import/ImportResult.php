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

    /** @var list<string> */
    public array $errors = [];

    public function summary(): string
    {
        $summary = \sprintf('%d nouvelle(s), %d mise(s) à jour, %d déjà connue(s), %d temps de passage calculé(s), %d fichier(s) ignoré(s)', $this->created, $this->updated, $this->unchanged, $this->withSplits, $this->ignored);

        return [] === $this->errors ? $summary : $summary.\sprintf(', %d erreur(s)', \count($this->errors));
    }
}
