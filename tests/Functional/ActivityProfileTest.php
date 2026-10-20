<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activity;
use App\Enum\Sport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Page détail : profil de la sortie (séries passées à assets/activity_profile.js, moyennes en HTML).
 */
final class ActivityProfileTest extends WebTestCase
{
    public function testShowsProfileWithAveragesAndOnlyAvailableSeries(): void
    {
        $client = static::createClient();
        $activity = new Activity('run', 'track_file', 'Footing', Sport::Run, new \DateTimeImmutable('2026-10-01 07:00'), 1000, 300, 300, averageHeartRate: 148.0);
        $points = [];
        for ($i = 0; $i <= 100; ++$i) {
            $points[] = [$i * 10.0, $i * 3, 20.0 + $i / 10, 140 + intdiv($i, 10), null];
        }
        $activity->replaceTrackData([], null, streamPoints: $points);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($activity);
        $entityManager->flush();
        $entityManager->clear();

        $client->request('GET', '/activites/run');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#activity-profile-title', 'Profil');
        $legend = $client->getCrawler()->filter('.activity-profile-legend li')->each(static fn ($li): string => preg_replace('/\s+/', ' ', trim($li->text())) ?? '');
        self::assertSame(['Allure moy. 5:00/km', 'Fréquence cardiaque moy. 148 bpm', 'Altitude 20 – 30 m'], $legend, 'Sans capteur : pas de cadence');
        $data = json_decode((string) $client->getCrawler()->filter('[data-activity-profile]')->attr('data-activity-profile'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['series']);
        self::assertIsArray($data['distances']);
        self::assertSame(['pace', 'heart_rate', 'altitude'], array_column($data['series'], 'key'));
        self::assertCount(101, $data['distances']);
    }

    public function testNoProfileWithoutStream(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new Activity('run', 'strava', 'Footing', Sport::Run, new \DateTimeImmutable('2026-10-01 07:00'), 1000, 300, 300));
        $entityManager->flush();

        $client->request('GET', '/activites/run');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#activity-profile-title');
    }
}
