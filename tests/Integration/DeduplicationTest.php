<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Activity;
use App\Enum\Sport;
use App\Repository\ActivityRepository;
use App\Repository\UpsertOutcome;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeduplicationTest extends KernelTestCase
{
    public function testTwoCsvActivitiesOneMinuteApartAreBothKept(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(ActivityRepository::class);
        $start = new \DateTimeImmutable('2026-04-07 10:25:30', new \DateTimeZone('UTC'));

        $first = $repository->upsert($this->activity('1', ActivityRepository::SOURCE_CSV, $start));
        $second = $repository->upsert($this->activity('2', ActivityRepository::SOURCE_CSV, $start->modify('+1 minute')));
        $repository->flush();

        self::assertSame(UpsertOutcome::Created, $first->outcome);
        self::assertSame(UpsertOutcome::Created, $second->outcome);
        self::assertSame(2, $repository->count());

        // Réimport identique : rien ne change, aucun doublon.
        self::assertSame(UpsertOutcome::Unchanged, $repository->upsert($this->activity('2', ActivityRepository::SOURCE_CSV, $start->modify('+1 minute')))->outcome);
    }

    public function testTrackOfTheSameSessionIsMergedIntoTheCsvActivity(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(ActivityRepository::class);
        $start = new \DateTimeImmutable('2026-04-07 10:25:30', new \DateTimeZone('UTC'));

        $repository->upsert($this->activity('1', ActivityRepository::SOURCE_CSV, $start));
        $track = $repository->upsert($this->activity('file:run.gpx', ActivityRepository::SOURCE_FILE, $start->modify('+40 seconds')));
        $repository->flush();

        self::assertSame(UpsertOutcome::Unchanged, $track->outcome);
        self::assertSame('1', $track->activity?->id);
        self::assertSame(1, $repository->count());
    }

    public function testStravaAndGarminExportsOfTheSameSessionKeepTheFirstImported(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(ActivityRepository::class);
        $start = new \DateTimeImmutable('2019-11-03 12:51:46', new \DateTimeZone('UTC'));

        $repository->upsert($this->activity('garmin:1', ActivityRepository::SOURCE_GARMIN, $start));
        $csv = $repository->upsert($this->activity('2', ActivityRepository::SOURCE_CSV, $start->modify('+30 seconds')));
        $track = $repository->upsert($this->activity('file:run.fit', ActivityRepository::SOURCE_FILE, $start->modify('+20 seconds')));
        $repository->flush();

        self::assertSame(UpsertOutcome::Unchanged, $csv->outcome);
        self::assertSame('garmin:1', $csv->activity?->id);
        self::assertSame(UpsertOutcome::Unchanged, $track->outcome);
        self::assertSame('garmin:1', $track->activity?->id);
        self::assertSame(1, $repository->count());
    }

    public function testTwoGarminActivitiesOneMinuteApartAreBothKept(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(ActivityRepository::class);
        $start = new \DateTimeImmutable('2019-11-03 12:51:46', new \DateTimeZone('UTC'));

        $repository->upsert($this->activity('garmin:1', ActivityRepository::SOURCE_GARMIN, $start));
        $second = $repository->upsert($this->activity('garmin:2', ActivityRepository::SOURCE_GARMIN, $start->modify('+1 minute')));
        $repository->flush();

        self::assertSame(UpsertOutcome::Created, $second->outcome);
        self::assertSame(2, $repository->count());
    }

    public function testDeletedActivityIsNeverRecreated(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(ActivityRepository::class);
        $start = new \DateTimeImmutable('2026-04-07 10:25:30', new \DateTimeZone('UTC'));

        $created = $repository->upsert($this->activity('garmin:1', ActivityRepository::SOURCE_GARMIN, $start))->activity;
        $repository->upsert($this->activity('garmin:2', ActivityRepository::SOURCE_GARMIN, $start->modify('+1 minute')));
        $repository->flush();
        self::assertNotNull($created);
        $repository->delete($created);

        // Réimport, même sortie par une autre source (CSV, trace, intervals.icu) : ignorés.
        self::assertSame(UpsertOutcome::Deleted, $repository->upsert($this->activity('garmin:1', ActivityRepository::SOURCE_GARMIN, $start))->outcome);
        $csv = $repository->upsert($this->activity('99', ActivityRepository::SOURCE_CSV, $start->modify('+30 seconds')));
        self::assertSame(UpsertOutcome::Deleted, $csv->outcome);
        self::assertNull($csv->activity);
        self::assertSame(UpsertOutcome::Deleted, $repository->upsert($this->activity('file:run.fit', ActivityRepository::SOURCE_FILE, $start->modify('+20 seconds')))->outcome);
        self::assertSame(UpsertOutcome::Deleted, $repository->upsert($this->activity('intervals:i1', 'intervals', $start))->outcome);

        // Autre sortie du même export une minute plus tard : conservée.
        self::assertSame(UpsertOutcome::Unchanged, $repository->upsert($this->activity('garmin:2', ActivityRepository::SOURCE_GARMIN, $start->modify('+1 minute')))->outcome);
        $repository->flush();
        self::assertSame(['garmin:2'], array_map(static fn (Activity $a): string => $a->id, $repository->findAll()));
    }

    private function activity(string $id, string $source, \DateTimeImmutable $start): Activity
    {
        return new Activity($id, $source, 'Footing', Sport::Run, $start, 8000, 2700, 2760);
    }
}
