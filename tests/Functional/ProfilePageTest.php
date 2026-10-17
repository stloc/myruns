<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProfilePageTest extends WebTestCase
{
    public function testProfileIsSavedAndValidated(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/profil');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Enregistrer')->form([
            'profile[maxHeartRate]' => '188',
            'profile[restingHeartRate]' => '170',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'nettement inférieure à la FC max');

        $crawler = $client->request('GET', '/profil');
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'profile[maxHeartRate]' => '188',
            'profile[restingHeartRate]' => '48',
            'profile[birthYear]' => '1985',
        ]));
        self::assertResponseRedirects('/profil');
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Profil enregistré');
        self::assertInputValueSame('profile[maxHeartRate]', '188');
        self::assertInputValueSame('profile[birthYear]', '1985');
    }

    public function testRaceCanBeAddedAndDeleted(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/profil');
        $client->submit($crawler->selectButton('Ajouter la course')->form([
            'race[date]' => (new \DateTimeImmutable('-1 month'))->format('Y-m-d'),
            'race[distance]' => '10k',
            'race[time]' => '45:00',
            'race[name]' => '10 km de Brest',
        ]));
        self::assertResponseRedirects('/profil');

        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('table', '10 km de Brest');
        self::assertSelectorTextContains('table', '45,3');

        $client->submit($crawler->selectButton('Supprimer')->form());
        self::assertResponseRedirects('/profil');
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Aucune course enregistrée');
    }

    public function testFutureRaceIsRejected(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/profil');
        $client->submit($crawler->selectButton('Ajouter la course')->form([
            'race[date]' => (new \DateTimeImmutable('+1 month'))->format('Y-m-d'),
            'race[distance]' => '10k',
            'race[time]' => '45:00',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('form', 'La course doit déjà avoir eu lieu');
    }
}
