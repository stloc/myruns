<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activity;
use App\Entity\AthleteProfile;
use App\Enum\SplitKind;
use App\Enum\Sport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Page détail : allure ajustée à la pente (résumé et temps de passage) et analyse de l'effort.
 */
final class EffortAnalysisTest extends WebTestCase
{
    public function testShowsGradeAdjustedPaceAndEffortWithoutZonesWhenProfileHasNoMaxHeartRate(): void
    {
        $client = static::createClient();
        $this->persistClimbingRun();

        $client->request('GET', '/activites/run');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.stat-grid', 'Allure ajustée à la pente');
        self::assertSelectorTextContains('#effort-title', 'Analyse de l\'effort');
        self::assertSelectorTextContains('section[aria-labelledby="effort-title"]', 'Meilleures allures');
        self::assertSelectorTextContains('section[aria-labelledby="effort-title"]', '1 700 pas');
        self::assertSelectorNotExists('.zone-list');
        self::assertSelectorTextContains('section[aria-labelledby="effort-title"]', 'Indiquez votre FC max');
        $adjusted = $client->getCrawler()->filter('.split-table tbody tr td:nth-child(6)')->each(static fn ($td): string => trim($td->text()));
        self::assertCount(2, $adjusted);
        self::assertNotSame('5:00/km', $adjusted[0], 'Km en montée : allure ajustée plus rapide que l\'allure réelle');
    }

    public function testShowsHeartRateZonesWithMaxHeartRate(): void
    {
        $client = static::createClient();
        $profile = new AthleteProfile();
        $profile->maxHeartRate = 190;
        $profile->restingHeartRate = 50;
        static::getContainer()->get(EntityManagerInterface::class)->persist($profile);
        $this->persistClimbingRun();

        $client->request('GET', '/activites/run');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(5, '.zone-list li');
        self::assertSelectorTextContains('.zone-list li.zone-3', '100 %');
    }

    private function persistClimbingRun(): void
    {
        $activity = new Activity('run', 'track_file', 'Côte', Sport::Run, new \DateTimeImmutable('2026-10-01 07:00'), 2000, 600, 600, averageHeartRate: 155.0);
        $points = [];
        for ($t = 0; $t <= 600; $t += 3) {
            $distance = $t / 0.3;
            // 155 bpm = 75 % de la réserve (50–190) : zone 3.
            $points[] = [$distance, $t, $distance <= 1000 ? 50 + 0.05 * $distance : 100.0, 155, 85.0];
        }
        $splits = [
            ['kind' => SplitKind::Kilometer, 'distance' => 1000.0, 'seconds' => 300.0, 'avg_hr' => 155, 'max_hr' => 155, 'elevation_gain' => 50.0, 'elevation_change' => 50.0],
            ['kind' => SplitKind::Kilometer, 'distance' => 1000.0, 'seconds' => 300.0, 'avg_hr' => 155, 'max_hr' => 155, 'elevation_gain' => 0.0, 'elevation_change' => 0.0],
        ];
        $activity->replaceTrackData($splits, null, streamPoints: $points);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($activity);
        $entityManager->flush();
        $entityManager->clear();
    }
}
