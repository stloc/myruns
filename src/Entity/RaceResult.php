<?php

declare(strict_types=1);

namespace App\Entity;

use App\Prediction\Vdot;
use App\Repository\RaceResultRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Résultat d'une course officielle : effort maximal, la donnée la plus fiable pour estimer la forme.
 */
#[ORM\Entity(repositoryClass: RaceResultRepository::class)]
class RaceResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        public private(set) \DateTimeImmutable $date,
        #[ORM\Column]
        public private(set) float $distanceMeters,
        #[ORM\Column]
        public private(set) int $timeSeconds,
        #[ORM\Column(length: 255)]
        public private(set) string $name = '',
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function vdot(): float
    {
        return Vdot::fromPerformance($this->distanceMeters, $this->timeSeconds);
    }

    public function paceSecondsPerKm(): float
    {
        return $this->timeSeconds / ($this->distanceMeters / 1000);
    }
}
