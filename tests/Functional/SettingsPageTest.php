<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SettingsPageTest extends WebTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        new Filesystem()->remove(\dirname(__DIR__, 2).'/var/test-data/settings.json');
    }

    public function testIntervalsKeySavedInTheAppEnablesTheSync(): void
    {
        $client = static::createClient();
        $client->request('GET', '/import');
        self::assertSelectorTextContains('main', 'Non configuré');

        $crawler = $client->request('GET', '/reglages');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Aucune clé enregistrée');

        $client->submit($crawler->selectButton('Enregistrer')->form([
            'settings[INTERVALS_API_KEY]' => 'cle-de-test-1234',
        ]));
        self::assertResponseRedirects('/reglages');
        $client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Clés enregistrées');
        self::assertSelectorTextContains('main', '…1234');
        self::assertSelectorTextNotContains('main', 'cle-de-test-1234');

        // La clé passe par SettingsEnvVarLoader : la synchro devient disponible.
        $client->request('GET', '/import');
        self::assertSelectorTextContains('main', 'Synchroniser maintenant');

        $crawler = $client->request('GET', '/reglages');
        $client->submit($crawler->selectButton('Effacer Clé API intervals.icu')->form());
        self::assertResponseRedirects('/reglages');
        $client->request('GET', '/import');
        self::assertSelectorTextContains('main', 'Non configuré');
    }
}
