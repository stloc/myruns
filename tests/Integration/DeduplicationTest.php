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
        self::assertSame('1', $track->activity->id);
        self::assertSame(1, $repository->count());
    }

    private function activity(string $id, string $source, \DateTimeImmutable $start): Activity
    {
        return new Activity($id, $source, 'Footing', Sport::Run, $start, 8000, 2700, 2760);
    }
}
