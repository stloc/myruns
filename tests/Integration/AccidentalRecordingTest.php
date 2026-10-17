<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Activity;
use App\Enum\Sport;
use App\Prediction\FitnessAnalyzer;
use App\Repository\ActivityRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Une montre lancée par erreur (quelques mètres) n'est pas une sortie : elle ne compte ni dans la
 * régularité ni dans le nombre de sorties par semaine du programme.
 */
final class AccidentalRecordingTest extends KernelTestCase
{
    public function testRecordingOfAFewMetersIsNotARun(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(ActivityRepository::class);
        $repository->upsert(new Activity('1', ActivityRepository::SOURCE_CSV, 'Footing', Sport::Run, new \DateTimeImmutable('2026-09-20 08:00:00'), 8000, 2700, 2700));
        $repository->upsert(new Activity('2', ActivityRepository::SOURCE_CSV, 'Course à pied le matin', Sport::Run, new \DateTimeImmutable('2026-09-24 07:46:00'), 3, 16, 16));
        $repository->flush();

        $fitness = self::getContainer()->get(FitnessAnalyzer::class)->analyze(new \DateTimeImmutable('2026-09-30 12:00:00'));

        self::assertSame(1, $fitness->runsLast4Weeks);
        self::assertSame(['2026-09-14' => 8.0, '2026-09-21' => 0.0, '2026-09-28' => 0.0], $repository->weeklyRunningKm(3, new \DateTimeImmutable('2026-09-30 12:00:00')));
        self::assertNotNull($repository->find('2'), 'L\'enregistrement reste visible dans la liste des activités.');
    }
}
