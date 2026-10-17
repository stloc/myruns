<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SplitKind;
use Doctrine\ORM\Mapping as ORM;

/**
 * Temps de passage d'une activité : kilomètre calculé ou tour enregistré par la montre.
 */
#[ORM\Entity]
class ActivitySplit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'splits')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) Activity $activity,
        #[ORM\Column(length: 3, enumType: SplitKind::class)]
        public private(set) SplitKind $kind,
        #[ORM\Column]
        public private(set) int $position,
        #[ORM\Column]
        public private(set) float $distanceMeters,
        #[ORM\Column]
        public private(set) float $seconds,
        #[ORM\Column(nullable: true)]
        public private(set) ?int $averageHeartRate = null,
        #[ORM\Column(nullable: true)]
        public private(set) ?int $maxHeartRate = null,
        #[ORM\Column(nullable: true)]
        public private(set) ?float $elevationGain = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function paceSecondsPerKm(): ?float
    {
        return $this->distanceMeters > 0 ? $this->seconds / ($this->distanceMeters / 1000) : null;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'position' => $this->position,
            'distance_m' => round($this->distanceMeters, 1),
            'seconds' => round($this->seconds, 1),
            'avg_hr' => $this->averageHeartRate,
            'max_hr' => $this->maxHeartRate,
            'elevation_gain_m' => null === $this->elevationGain ? null : round($this->elevationGain, 1),
        ];
    }
}
