<?php

declare(strict_types=1);

namespace App\Tests;

/**
 * Génère un faux activities.csv au format de l'export de compte Strava (en-têtes anglais,
 * colonnes « Elapsed Time » et « Distance » en double), avec des dates relatives à aujourd'hui.
 */
final class StravaExportFactory
{
    public const HEADER = ['Activity ID', 'Activity Date', 'Activity Name', 'Activity Type', 'Activity Description', 'Elapsed Time', 'Distance', 'Max Heart Rate', 'Relative Effort', 'Commute', 'Filename', 'Elapsed Time', 'Moving Time', 'Distance', 'Elevation Gain', 'Average Heart Rate'];

    /**
     * 3 sorties par semaine pendant 12 semaines : footing 8 km à 5:30/km, séance 8 km à 4:40/km,
     * sortie longue 14 km à 5:45/km. VDOT attendu ≈ 44-45.
     */
    public static function create(string $directory, \DateTimeImmutable $now = new \DateTimeImmutable()): string
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }
        $path = $directory.'/activities.csv';
        $handle = fopen($path, 'w');
        \assert(false !== $handle);
        fputcsv($handle, self::HEADER, escape: '');

        $id = 1000;
        foreach (range(83, 1) as $daysAgo) {
            [$name, $km, $pace] = match ($daysAgo % 7) {
                1 => ['Footing', 8.0, 330],
                3 => ['Séance seuil', 8.0, 280],
                5 => ['Sortie longue', 14.0, 345],
                default => [null, 0.0, 0],
            };
            if (null === $name) {
                continue;
            }
            $date = $now->setTimezone(new \DateTimeZone('UTC'))->modify(\sprintf('-%d days', $daysAgo))->setTime(7, 0);
            $moving = (int) ($km * $pace);
            fputcsv($handle, [++$id, $date->format('M j, Y, g:i:s A'), $name, 'Run', '', $moving + 30, number_format($km, 2, '.', ''), 175, 40, 'false', '', $moving + 30, $moving, $km * 1000, 20, 150], escape: '');
        }
        fclose($handle);

        return $path;
    }
}
