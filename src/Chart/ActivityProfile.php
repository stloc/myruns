<?php

declare(strict_types=1);

namespace App\Chart;

use App\Entity\Activity;
use App\Enum\Sport;
use App\Util\Units;

/**
 * Profil de la sortie sur la distance : allure (ou vitesse), FC, cadence et altitude du terrain,
 * dessiné par assets/activity_profile.js. Allure lissée sur PACE_WINDOW_METERS, arrêts exclus
 * comme pour les temps de passage ; cadence en pas par minute à pied (la montre compte les foulées).
 */
final readonly class ActivityProfile
{
    private const PACE_WINDOW_METERS = 200.0;
    /** Même seuil que SplitCalculator : en dessous, la montre est considérée à l'arrêt. */
    private const MOVING_SPEED_THRESHOLD = 0.5;

    /**
     * @param list<float>                                                                                                           $distances km
     * @param list<array{key: string, label: string, unit: string, format: string, values: list<int|float|null>, average: ?string}> $series
     */
    private function __construct(
        public array $distances,
        public array $series,
    ) {
    }

    public static function fromActivity(Activity $activity): ?self
    {
        $points = $activity->streamPoints();
        if (\count($points) < 2) {
            return null;
        }

        $sport = $activity->sport;
        $pace = $sport->isRunning();
        $series = [];

        $paces = self::smoothedPaces($points);
        $average = $activity->distanceMeters > 0 && $activity->movingTimeSeconds > 0 ? $activity->movingTimeSeconds / $activity->distanceMeters * 1000 : null;
        $series[] = $pace
            ? ['key' => 'pace', 'label' => 'Allure', 'unit' => '/km', 'format' => 'pace', 'values' => array_map(static fn (?float $p): ?float => null === $p ? null : round($p), $paces), 'average' => Units::formatPace($average)]
            : ['key' => 'pace', 'label' => 'Vitesse', 'unit' => 'km/h', 'format' => 'decimal', 'values' => array_map(static fn (?float $p): ?float => null === $p ? null : round(3600 / $p, 1), $paces), 'average' => null === $average ? null : number_format(3600 / $average, 1, ',', ' ').' km/h'];

        $heartRates = array_column($points, 3);
        $series[] = [
            'key' => 'heart_rate', 'label' => 'Fréquence cardiaque', 'unit' => 'bpm', 'format' => 'integer', 'values' => $heartRates,
            'average' => null !== $activity->averageHeartRate ? number_format($activity->averageHeartRate, 0).' bpm' : self::averageLabel($heartRates, 'bpm'),
        ];

        $steps = $pace || \in_array($sport, [Sport::Walk, Sport::Hike], true);
        $unit = $steps ? 'ppm' : 'rpm';
        $cadences = array_map(static fn (?float $c): ?int => null === $c ? null : (int) round($steps ? 2 * $c : $c), array_column($points, 4));
        // Moyenne en mouvement : la cadence nulle des arrêts ne compte pas.
        $series[] = ['key' => 'cadence', 'label' => 'Cadence', 'unit' => $unit, 'format' => 'integer', 'values' => $cadences, 'average' => self::averageLabel(array_filter($cadences), $unit)];

        $altitudes = array_column($points, 2);
        $known = array_filter($altitudes, static fn (?float $a): bool => null !== $a);
        $series[] = [
            'key' => 'altitude', 'label' => 'Altitude', 'unit' => 'm', 'format' => 'integer', 'values' => $altitudes,
            'average' => [] === $known ? null : \sprintf('%s – %s m', number_format(min($known), 0, ',', ' '), number_format(max($known), 0, ',', ' ')),
        ];

        // Une série sans au moins deux valeurs n'apporte rien (pas de ceinture, pas de capteur de cadence…).
        $series = array_values(array_filter($series, static fn (array $s): bool => \count(array_filter($s['values'], static fn (int|float|null $v): bool => null !== $v)) >= 2));

        return [] === $series ? null : new self(array_map(static fn (array $p): float => round($p[0] / 1000, 3), $points), $series);
    }

    /**
     * @return array{distances: list<float>, series: list<array{key: string, label: string, unit: string, format: string, values: list<int|float|null>}>}
     */
    public function toArray(): array
    {
        return [
            'distances' => $this->distances,
            'series' => array_map(static fn (array $s): array => ['key' => $s['key'], 'label' => $s['label'], 'unit' => $s['unit'], 'format' => $s['format'], 'values' => $s['values']], $this->series),
        ];
    }

    /**
     * Allure (s/km) en chaque point, sur la fenêtre de PACE_WINDOW_METERS centrée sur lui ; null à l'arrêt.
     *
     * @param list<array{0: float, 1: int, 2: ?float, 3: ?int, 4: ?float}> $points
     *
     * @return list<?float>
     */
    private static function smoothedPaces(array $points): array
    {
        $count = \count($points);
        $paces = [];
        $from = 0;
        $to = 0;
        foreach ($points as $i => $point) {
            while ($points[$from][0] < $point[0] - self::PACE_WINDOW_METERS / 2) {
                ++$from;
            }
            while ($to < $count - 1 && $points[$to + 1][0] <= $point[0] + self::PACE_WINDOW_METERS / 2) {
                ++$to;
            }

            $meters = 0.0;
            $seconds = 0;
            for ($j = $from + 1; $j <= $to; ++$j) {
                $dd = $points[$j][0] - $points[$j - 1][0];
                $dt = $points[$j][1] - $points[$j - 1][1];
                if ($dt > 0 && $dd / $dt >= self::MOVING_SPEED_THRESHOLD) {
                    $meters += $dd;
                    $seconds += $dt;
                }
            }
            // Moins de la moitié de la fenêtre parcourue en mouvement : arrêt (feu rouge, ravito…).
            $window = $points[$to][0] - $points[$from][0];
            $paces[] = $seconds > 0 && $meters > 0 && $meters >= $window / 2 ? $seconds / $meters * 1000 : null;
        }

        return $paces;
    }

    /**
     * @param array<int|null> $values
     */
    private static function averageLabel(array $values, string $unit): ?string
    {
        $values = array_filter($values, static fn (?int $v): bool => null !== $v);

        return [] === $values ? null : number_format(array_sum($values) / \count($values), 0).' '.$unit;
    }
}
