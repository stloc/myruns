<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Activity;
use App\Entity\RaceResult;
use App\Enum\Sport;
use App\Repository\ActivityRepository;
use App\Repository\RaceResultRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * SQLite ne garde pas le fuseau horaire et compare les dates comme du texte : ces règles le figent.
 */
final class SqliteDatesTest extends KernelTestCase
{
    public function testStartDateKeepsTheSameInstantWhateverTheTimezone(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(ActivityRepository::class);
        $paris = new \DateTimeImmutable('2026-07-14 08:00:00', new \DateTimeZone('Europe/Paris'));

        $repository->upsert($this->runActivity('1', $paris, 10000));
        $repository->flush();
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $reloaded = $repository->find('1');
        self::assertInstanceOf(Activity::class, $reloaded);
        self::assertSame($paris->getTimestamp(), $reloaded->startDate->getTimestamp());
        self::assertSame('202607140600', $reloaded->startKey);
    }

    public function testWeeklyKmGroupsByLocalMonday(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(ActivityRepository::class);
        $paris = new \DateTimeZone('Europe/Paris');

        // Lundi 00:30 à Paris = dimanche 22:30 UTC : compte pour la semaine du lundi.
        $repository->upsert($this->runActivity('1', new \DateTimeImmutable('2026-07-13 00:30:00', $paris), 10000));
        $repository->upsert($this->runActivity('2', new \DateTimeImmutable('2026-07-12 18:00:00', $paris), 5000));
        $repository->flush();

        $weekly = $repository->weeklyRunningKm(2, new \DateTimeImmutable('2026-07-15 12:00:00', $paris));

        self::assertSame(['2026-07-06' => 5.0, '2026-07-13' => 10.0], $weekly);
    }

    public function testRaceOnTheBoundaryDayIsIncluded(): void
    {
        self::bootKernel();
        $races = self::getContainer()->get(RaceResultRepository::class);
        $races->save(new RaceResult(new \DateTimeImmutable('2026-05-10'), 10000, 2400, '10 km'));

        $found = $races->findBetween(new \DateTimeImmutable('2026-05-10 15:00:00'), new \DateTimeImmutable('2026-05-10 09:00:00'));

        self::assertCount(1, $found);
    }

    private function runActivity(string $id, \DateTimeImmutable $start, float $meters): Activity
    {
        return new Activity($id, ActivityRepository::SOURCE_CSV, 'Footing', Sport::Run, $start, $meters, 3000, 3000);
    }
}
