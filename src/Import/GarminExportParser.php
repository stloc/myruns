<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Activity;
use App\Enum\Sport;
use App\Repository\ActivityRepository;

/**
 * Lit le résumé des activités de l'export de compte Garmin Connect
 * (DI_CONNECT/DI-Connect-Fitness/<compte>_<n>_summarizedActivities.json).
 *
 * Unités de Garmin : distances et D+ en centimètres, durées et dates en millisecondes.
 * Le fichier ne cite pas le FIT correspondant : la trace est rattachée par l'heure de départ.
 */
final class GarminExportParser
{
    public static function supports(string $filename): bool
    {
        return (bool) preg_match('/summarizedActivities\.json$/i', $filename);
    }

    /**
     * @return iterable<Activity>
     */
    public function parse(string $contents): iterable
    {
        try {
            $data = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Résumé des activités Garmin illisible : '.$e->getMessage(), previous: $e);
        }

        foreach (\is_array($data) ? $data : [] as $export) {
            $activities = \is_array($export) ? ($export['summarizedActivitiesExport'] ?? null) : null;
            foreach (\is_array($activities) ? $activities : [] as $row) {
                if (\is_array($row)) {
                    $activity = $this->activity($row);
                    if (null !== $activity) {
                        yield $activity;
                    }
                }
            }
        }
    }

    /**
     * @param array<mixed> $row
     */
    private function activity(array $row): ?Activity
    {
        $id = $row['activityId'] ?? null;
        $start = self::number($row, 'startTimeGmt') ?? self::number($row, 'beginTimestamp');
        if (!\is_int($id) && !\is_string($id) || null === $start) {
            return null;
        }

        $name = \is_string($row['name'] ?? null) ? trim($row['name']) : '';
        // « trail_running », « street_running », « mountain_biking »…
        $type = \is_string($row['activityType'] ?? null) ? str_replace('_', ' ', $row['activityType']) : '';
        $elapsed = self::number($row, 'elapsedDuration') ?? self::number($row, 'duration') ?? 0.0;
        $moving = self::number($row, 'movingDuration') ?? 0.0;
        $gain = self::number($row, 'elevationGain');
        $heartRate = self::number($row, 'avgHr');
        $maxHeartRate = self::number($row, 'maxHr');

        return new Activity(
            id: 'garmin:'.$id,
            source: ActivityRepository::SOURCE_GARMIN,
            name: $name,
            sport: Sport::fromLabel($type)->refinedByName($name),
            startDate: (new \DateTimeImmutable())->setTimestamp(intdiv((int) $start, 1000))->setTimezone(new \DateTimeZone('UTC')),
            distanceMeters: round((self::number($row, 'distance') ?? 0.0) / 100, 1),
            movingTimeSeconds: (int) round(($moving > 0 ? $moving : $elapsed) / 1000),
            elapsedTimeSeconds: (int) round($elapsed / 1000),
            elevationGain: null === $gain ? null : round($gain / 100),
            averageHeartRate: $heartRate > 0 ? $heartRate : null,
            maxHeartRate: $maxHeartRate > 0 ? $maxHeartRate : null,
        );
    }

    /**
     * @param array<mixed> $row
     */
    private static function number(array $row, string $key): ?float
    {
        $value = $row[$key] ?? null;

        return \is_int($value) || \is_float($value) ? (float) $value : null;
    }
}
