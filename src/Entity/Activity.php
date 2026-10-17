<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Enum\SplitKind;
use App\Enum\Sport;
use App\Repository\ActivityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Activité sportive importée (export Strava ou trace GPX/TCX).
 *
 * Les propriétés sont lisibles publiquement mais ne se modifient que via replaceWith().
 */
#[ORM\Entity(repositoryClass: ActivityRepository::class)]
#[ORM\Index(name: 'idx_activity_start_key', columns: ['start_key'])]
#[ORM\Index(name: 'idx_activity_sport_start_date', columns: ['sport', 'start_date'])]
class Activity
{
    /** Minute de départ UTC (AAAAMMJJHHMM), clé de dédoublonnage entre sources. */
    #[ORM\Column(length: 12)]
    public private(set) string $startKey;

    /** FC la plus haute tenue au moins 10 s (calculée sur la trace : écarte les pics du capteur). */
    #[ORM\Column(nullable: true)]
    public private(set) ?int $sustainedMaxHeartRate = null;

    /** D+ recalculé sur l'altitude du terrain (IGN) à partir des positions GPS de la trace. */
    #[ORM\Column(nullable: true)]
    public private(set) ?float $groundElevationGain = null;

    /** @var Collection<int, ActivitySplit> */
    #[ORM\OneToMany(targetEntity: ActivitySplit::class, mappedBy: 'activity', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['kind' => \SortDirection::Ascending, 'position' => \SortDirection::Ascending])]
    private Collection $splits;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        public private(set) string $id,
        #[ORM\Column(length: 20)]
        public private(set) string $source,
        #[ORM\Column(length: 255)]
        public private(set) string $name,
        #[ORM\Column(length: 20, enumType: Sport::class)]
        public private(set) Sport $sport,
        #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
        public private(set) \DateTimeImmutable $startDate,
        #[ORM\Column]
        public private(set) float $distanceMeters,
        #[ORM\Column]
        public private(set) int $movingTimeSeconds,
        #[ORM\Column]
        public private(set) int $elapsedTimeSeconds,
        #[ORM\Column(nullable: true)]
        public private(set) ?float $elevationGain = null,
        #[ORM\Column(nullable: true)]
        public private(set) ?float $averageHeartRate = null,
        #[ORM\Column(nullable: true)]
        public private(set) ?float $maxHeartRate = null,
        /** Fichier de trace de l'export (« activities/123.fit.gz »), pour y rattacher les temps de passage. */
        #[ORM\Column(length: 255, nullable: true)]
        public private(set) ?string $trackFile = null,
    ) {
        $this->startKey = self::keyFor($startDate);
        $this->splits = new ArrayCollection();
    }

    public static function keyFor(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('YmdHi');
    }

    /**
     * Reprend les données d'une autre lecture de la même sortie (même identifiant).
     */
    public function replaceWith(self $other): void
    {
        if ($other->id !== $this->id) {
            throw new \LogicException(\sprintf('Impossible de remplacer l\'activité « %s » par « %s ».', $this->id, $other->id));
        }

        $this->source = $other->source;
        $this->name = $other->name;
        $this->sport = $other->sport;
        $this->startDate = $other->startDate;
        $this->startKey = $other->startKey;
        $this->distanceMeters = $other->distanceMeters;
        $this->movingTimeSeconds = $other->movingTimeSeconds;
        $this->elapsedTimeSeconds = $other->elapsedTimeSeconds;
        $this->elevationGain = $other->elevationGain;
        $this->averageHeartRate = $other->averageHeartRate;
        $this->maxHeartRate = $other->maxHeartRate;
        $this->trackFile = $other->trackFile;
    }

    /**
     * @return list<ActivitySplit>
     */
    public function splitsOf(SplitKind $kind): array
    {
        return array_values($this->splits->filter(static fn (ActivitySplit $split): bool => $split->kind === $kind)->toArray());
    }

    public function hasSplits(): bool
    {
        return !$this->splits->isEmpty();
    }

    /**
     * Remplace les temps de passage issus de la trace (sans toucher à la base s'ils sont identiques).
     *
     * @param list<array{kind: SplitKind, distance: float, seconds: float, avg_hr: ?int, max_hr: ?int, elevation_gain: ?float}> $splits
     *
     * @return bool true si quelque chose a changé
     */
    public function replaceTrackData(array $splits, ?int $sustainedMaxHeartRate, ?float $groundElevationGain = null): bool
    {
        $positions = [];
        $new = [];
        foreach ($splits as $split) {
            $position = $positions[$split['kind']->value] = ($positions[$split['kind']->value] ?? 0) + 1;
            $new[] = new ActivitySplit($this, $split['kind'], $position, $split['distance'], $split['seconds'], $split['avg_hr'], $split['max_hr'], $split['elevation_gain']);
        }

        if (self::describeSplits($this->splits->toArray()) === self::describeSplits($new) && $this->sustainedMaxHeartRate === $sustainedMaxHeartRate && $this->groundElevationGain === $groundElevationGain) {
            return false;
        }

        $this->splits->clear();
        foreach ($new as $split) {
            $this->splits->add($split);
        }
        $this->sustainedMaxHeartRate = $sustainedMaxHeartRate;
        $this->groundElevationGain = $groundElevationGain;

        return true;
    }

    /**
     * D+ le plus fiable : celui du terrain s'il a pu être calculé, sinon celui de la source.
     */
    public function effectiveElevationGain(): ?float
    {
        return $this->groundElevationGain ?? $this->elevationGain;
    }

    /**
     * @param array<int, ActivitySplit> $splits
     *
     * @return list<array<string, scalar|null>>
     */
    private static function describeSplits(array $splits): array
    {
        return array_values(array_map(static fn (ActivitySplit $split): array => $split->toArray(), $splits));
    }

    /** Allure en secondes par km, ou null si non calculable. */
    public function paceSecondsPerKm(): ?float
    {
        if ($this->distanceMeters <= 0 || $this->movingTimeSeconds <= 0) {
            return null;
        }

        return $this->movingTimeSeconds / ($this->distanceMeters / 1000);
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'name' => $this->name,
            'sport' => $this->sport->value,
            'start_date' => $this->startDate->getTimestamp(),
            'distance_m' => $this->distanceMeters,
            'moving_time_s' => $this->movingTimeSeconds,
            'elapsed_time_s' => $this->elapsedTimeSeconds,
            'elevation_gain_m' => $this->elevationGain,
            'avg_hr' => $this->averageHeartRate,
            'max_hr' => $this->maxHeartRate,
            'track_file' => $this->trackFile,
        ];
    }
}
