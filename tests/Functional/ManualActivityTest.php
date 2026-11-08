<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activity;
use App\Enum\Sport;
use App\Repository\ActivityRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ManualActivityTest extends WebTestCase
{
    public function testActivityCanBeAdded(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/activites/nouvelle');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Ajouter l\'activité')->form([
            'manual_activity[sport]' => Sport::Run->value,
            'manual_activity[startDate]' => (new \DateTimeImmutable('-2 days'))->format('Y-m-d\TH:i'),
            'manual_activity[distanceKm]' => '10.5',
            'manual_activity[duration]' => '55:00',
            'manual_activity[name]' => 'Footing tapis',
            'manual_activity[averageHeartRate]' => '150',
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Footing tapis');

        $activity = static::getContainer()->get(ActivityRepository::class)->findOneBy(['name' => 'Footing tapis']);
        self::assertInstanceOf(Activity::class, $activity);
        self::assertSame(ActivityRepository::SOURCE_MANUAL, $activity->source);
        self::assertSame(10500.0, $activity->distanceMeters);
        self::assertSame(3300, $activity->movingTimeSeconds);
    }

    public function testInvalidInputIsRejected(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/activites/nouvelle');
        $client->submit($crawler->selectButton('Ajouter l\'activité')->form([
            'manual_activity[startDate]' => (new \DateTimeImmutable('-2 days'))->format('Y-m-d\TH:i'),
            'manual_activity[distanceKm]' => '10',
            'manual_activity[duration]' => 'abc',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'non reconnu');
    }
}
