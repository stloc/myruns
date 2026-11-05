<?php

declare(strict_types=1);

namespace App\Training;

final readonly class TrainingSession
{
    /**
     * @param ?int $weekday jour conseillé, 1 (lundi) à 7 (dimanche)
     * @param bool $edited  contenu saisi par l'utilisateur à la place de celui du programme
     */
    public function __construct(
        public SessionType $type,
        public string $description,
        public float $distanceKm,
        public ?int $weekday = null,
        public bool $edited = false,
    ) {
    }

    public function on(int $weekday): self
    {
        return new self($this->type, $this->description, $this->distanceKm, $weekday, $this->edited);
    }
}
