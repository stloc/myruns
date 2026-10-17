<?php

declare(strict_types=1);

namespace App\Sync;

use App\Entity\Activity;
use App\Enum\Sport;
use App\Import\ActivityImporter;
use App\Import\ImportResult;
use App\Repository\ActivityRepository;
use App\Repository\AthleteProfileRepository;
use App\Repository\UpsertOutcome;

/**
 * Synchronise les nouvelles séances depuis intervals.icu (montre → intervals.icu → MyStrava).
 *
 * - Les séances arrivées dans intervals.icu par Strava sont ignorées : leur fichier n'est pas
 *   transmis par l'API (règles de Strava), et elles viennent de toute façon de la montre.
 * - Une séance déjà connue (par exemple via l'archive Strava) n'est pas dupliquée : même heure
 *   de départ à ±1 minute. Le fichier n'est téléchargé que si les temps de passage manquent.
 */
final class IntervalsSynchronizer
{
    public const SOURCE = 'intervals';
    /** Recouvrement avec la dernière séance connue, pour rattraper une synchro montre tardive. */
    private const OVERLAP_DAYS = 3;
    private const FIRST_SYNC_DAYS = 30;
    private const RESTING_HR_DAYS = 30;

    private const SPORTS = [
        'Run' => Sport::Run,
        'TrailRun' => Sport::TrailRun,
        'VirtualRun' => Sport::VirtualRun,
        'Ride' => Sport::Ride,
        'VirtualRide' => Sport::Ride,
        'GravelRide' => Sport::Ride,
        'MountainBikeRide' => Sport::Ride,
        'Walk' => Sport::Walk,
        'Hike' => Sport::Hike,
        'Swim' => Sport::Swim,
        'OpenWaterSwim' => Sport::Swim,
    ];

    public function __construct(
        private readonly IntervalsClient $client,
        private readonly ActivityRepository $activities,
        private readonly AthleteProfileRepository $profiles,
        private readonly ActivityImporter $importer,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function sync(?\DateTimeImmutable $since = null, \DateTimeImmutable $now = new \DateTimeImmutable()): SyncResult
    {
        $since ??= $this->activities->findMostRecent()?->startDate->modify(\sprintf('-%d days', self::OVERLAP_DAYS))
            ?? $now->modify(\sprintf('-%d days', self::FIRST_SYNC_DAYS));

        $result = new SyncResult($since);
        foreach ($this->client->activities($since, $now) as $remote) {
            if ('STRAVA' === strtoupper(self::string($remote, 'source'))) {
                ++$result->import->ignored;
                continue;
            }

            $summary = $this->toActivity($remote);
            if (null === $summary) {
                ++$result->import->ignored;
                continue;
            }

            $upsert = $this->activities->upsert($summary);
            match ($upsert->outcome) {
                UpsertOutcome::Created => ++$result->import->created,
                UpsertOutcome::Updated => ++$result->import->updated,
                UpsertOutcome::Unchanged => ++$result->import->unchanged,
            };

            if (!$upsert->activity->hasSplits() && '' !== self::string($remote, 'file_type')) {
                $this->attachFile($upsert->activity, self::string($remote, 'id'), self::string($remote, 'file_type'), $result->import);
            }
        }

        $this->activities->flush();
        $result->restingHeartRate = $this->updateRestingHeartRate($now);

        return $result;
    }

    private function attachFile(Activity $activity, string $remoteId, string $fileType, ImportResult $result): void
    {
        try {
            $contents = $this->client->originalFile($remoteId);
        } catch (\Throwable $e) {
            $result->errors[] = \sprintf('%s : téléchargement impossible (%s)', $remoteId, $e->getMessage());

            return;
        }

        $this->importer->attachTrack($activity, $remoteId.'.'.strtolower($fileType), $contents, $result);
    }

    /**
     * Remplit la FC de repos du profil (médiane sur 30 jours) si elle n'a jamais été saisie.
     * Une valeur saisie à la main n'est jamais écrasée.
     */
    private function updateRestingHeartRate(\DateTimeImmutable $now): ?int
    {
        $values = [];
        foreach ($this->client->wellness($now->modify(\sprintf('-%d days', self::RESTING_HR_DAYS)), $now) as $day) {
            $value = $day['restingHR'] ?? null;
            if (is_numeric($value) && $value > 25 && $value < 120) {
                $values[] = (int) $value;
            }
        }
        if ([] === $values) {
            return null;
        }

        sort($values);
        $median = $values[intdiv(\count($values), 2)];

        $profile = $this->profiles->current();
        if (null === $profile->restingHeartRate) {
            $profile->restingHeartRate = $median;
            $this->profiles->save($profile);
        }

        return $median;
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function toActivity(array $remote): ?Activity
    {
        $id = self::string($remote, 'id');
        $start = self::string($remote, 'start_date');
        if ('' === $id || '' === $start) {
            return null;
        }

        $moving = (int) self::number($remote, 'moving_time');
        $elapsed = (int) self::number($remote, 'elapsed_time');
        $type = self::string($remote, 'type');

        return new Activity(
            id: self::SOURCE.':'.$id,
            source: self::SOURCE,
            name: self::string($remote, 'name'),
            sport: self::SPORTS[$type] ?? Sport::fromLabel($type),
            startDate: (new \DateTimeImmutable($start))->setTimezone(new \DateTimeZone('UTC')),
            distanceMeters: self::number($remote, 'distance') ?? 0.0,
            movingTimeSeconds: $moving > 0 ? $moving : $elapsed,
            elapsedTimeSeconds: $elapsed > 0 ? $elapsed : $moving,
            elevationGain: self::number($remote, 'total_elevation_gain'),
            averageHeartRate: self::number($remote, 'average_heartrate'),
            maxHeartRate: self::number($remote, 'max_heartrate'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        return \is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function number(array $data, string $key): ?float
    {
        return is_numeric($data[$key] ?? null) ? (float) $data[$key] : null;
    }
}
