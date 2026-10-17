<?php

declare(strict_types=1);

namespace App\Chart;

use App\Enum\Sport;
use App\Util\Units;

/**
 * Carte des départs de la page Activités : un point par sortie avec trace GPS, regroupés selon
 * le zoom par assets/activities_map.js.
 */
final readonly class StartMap
{
    /**
     * @param list<array{0: float, 1: float, 2: string, 3: string}>             $points latitude, longitude, libellé, lien
     * @param array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}} $bounds sud-ouest, nord-est
     */
    private function __construct(
        public array $points,
        public array $bounds,
    ) {
    }

    /**
     * @param list<array{id: string, name: string, sport: Sport, startDate: \DateTimeImmutable, distanceMeters: float, latitude: float, longitude: float}> $positions du plus récent au plus ancien
     * @param \Closure(string): string                                                                                                                     $url       lien vers le détail, d'après l'identifiant
     */
    public static function fromPositions(array $positions, \Closure $url): ?self
    {
        if ([] === $positions) {
            return null;
        }

        $timezone = new \DateTimeZone(date_default_timezone_get());
        $points = [];
        foreach ($positions as $position) {
            $label = \sprintf('%s · %s', $position['name'], $position['startDate']->setTimezone($timezone)->format('d/m/Y'));
            if ($position['distanceMeters'] > 0) {
                $label .= ' · '.Units::formatDistance($position['distanceMeters']);
            }
            $points[] = [round($position['latitude'], 5), round($position['longitude'], 5), $label, $url($position['id'])];
        }

        $latitudes = array_column($points, 0);
        $longitudes = array_column($points, 1);

        return new self($points, [[min($latitudes), min($longitudes)], [max($latitudes), max($longitudes)]]);
    }
}
