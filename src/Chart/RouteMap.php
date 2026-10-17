<?php

declare(strict_types=1);

namespace App\Chart;

use App\Util\Units;

/**
 * Carte de la trace : tronçons d'environ SEGMENT_METERS colorés selon l'allure, en CLASSES
 * classes de même effectif (1 = le plus rapide de la sortie). Arrêts exclus du temps, comme
 * pour les temps de passage ; un tronçon presque entièrement à l'arrêt prend la classe « stop ».
 */
final readonly class RouteMap
{
    public const CLASSES = 5;
    private const SEGMENT_METERS = 100.0;
    /** Même seuil que SplitCalculator : en dessous, la montre est considérée à l'arrêt. */
    private const MOVING_SPEED_THRESHOLD = 0.5;

    /**
     * @param list<array{points: list<array{0: float, 1: float}>, class: string, label: string}> $segments
     * @param list<array{class: string, label: string}>                                          $legend
     * @param array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}                  $bounds   sud-ouest, nord-est
     */
    private function __construct(
        public array $segments,
        public array $legend,
        public array $bounds,
    ) {
    }

    /**
     * @param list<array{0: float, 1: float, 2: float, 3: int}> $points trace simplifiée (ActivityRoute)
     * @param bool                                              $pace   allure en min/km (course), sinon vitesse en km/h
     */
    public static function fromPoints(array $points, bool $pace): ?self
    {
        if (\count($points) < 2) {
            return null;
        }

        $raw = self::cut($points);
        $moving = array_values(array_filter(array_map(static fn (array $s): ?float => $s['pace'], $raw)));

        $segments = [];
        $ranges = [];
        foreach ($raw as $segment) {
            $class = 'stop';
            if (null !== $segment['pace']) {
                // Rang dans la sortie (tronçons plus rapides que celui-ci), ramené à CLASSES classes.
                $faster = \count(array_filter($moving, static fn (float $p): bool => $p < $segment['pace']));
                $rank = 1 + intdiv($faster * self::CLASSES, \count($moving));
                $class = 'pace-'.$rank;
                $ranges[$rank] = [min($ranges[$rank][0] ?? \INF, $segment['pace']), max($ranges[$rank][1] ?? 0.0, $segment['pace'])];
            }
            $segments[] = [
                'points' => $segment['points'],
                'class' => $class,
                'label' => null === $segment['pace'] ? 'Arrêt' : self::format($segment['pace'], $pace),
            ];
        }

        ksort($ranges);
        $legend = [];
        foreach ($ranges as $rank => [$fastest, $slowest]) {
            $legend[] = [
                'class' => 'pace-'.$rank,
                'label' => $pace
                    ? \sprintf('%s – %s', self::format($fastest, true), self::format($slowest, true))
                    : \sprintf('%s – %s', self::format($slowest, false), self::format($fastest, false)),
            ];
        }
        if (\in_array('stop', array_column($segments, 'class'), true)) {
            $legend[] = ['class' => 'stop', 'label' => 'Arrêt'];
        }

        $latitudes = array_column($points, 0);
        $longitudes = array_column($points, 1);

        return new self($segments, $legend, [[min($latitudes), min($longitudes)], [max($latitudes), max($longitudes)]]);
    }

    /**
     * @return array{segments: list<array{points: list<array{0: float, 1: float}>, class: string, label: string}>, bounds: array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}}
     */
    public function toArray(): array
    {
        return ['segments' => $this->segments, 'bounds' => $this->bounds];
    }

    /**
     * Découpe la trace en tronçons ; le dernier, trop court pour une allure fiable, rejoint le précédent.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: int}> $points
     *
     * @return list<array{points: list<array{0: float, 1: float}>, pace: ?float}> allure en s/km, null à l'arrêt
     */
    private static function cut(array $points): array
    {
        $segments = [];
        $current = [[$points[0][0], $points[0][1]]];
        $start = $points[0][2];
        $movingMeters = 0.0;
        $movingSeconds = 0;

        $count = \count($points);
        for ($i = 1; $i < $count; ++$i) {
            [$lat, $lon, $distance, $seconds] = $points[$i];
            $dd = $distance - $points[$i - 1][2];
            $dt = $seconds - $points[$i - 1][3];
            if ($dt > 0 && $dd / $dt >= self::MOVING_SPEED_THRESHOLD) {
                $movingMeters += $dd;
                $movingSeconds += $dt;
            }
            $current[] = [$lat, $lon];

            $length = $distance - $start;
            $last = $i === $count - 1;
            if ($length < self::SEGMENT_METERS && !$last) {
                continue;
            }

            if ($last && $length < self::SEGMENT_METERS / 2 && [] !== $segments) {
                $previous = array_pop($segments);
                $segments[] = ['points' => [...$previous['points'], ...\array_slice($current, 1)], 'pace' => $previous['pace']];
                break;
            }

            $segments[] = [
                'points' => $current,
                // Moins de la moitié du tronçon parcourue en mouvement : arrêt (feu rouge, ravito…).
                'pace' => $movingSeconds > 0 && $movingMeters >= $length / 2 ? $movingSeconds / $movingMeters * 1000 : null,
            ];
            $current = [[$lat, $lon]];
            $start = $distance;
            $movingMeters = 0.0;
            $movingSeconds = 0;
        }

        return $segments;
    }

    private static function format(float $secondsPerKm, bool $pace): string
    {
        return $pace ? Units::formatPace($secondsPerKm) : number_format(3600 / $secondsPerKm, 1, ',', '').' km/h';
    }
}
