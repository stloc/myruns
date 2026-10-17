<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\TrainingWeekOverrideRepository;
use App\Tests\Fit\FitFileBuilder;
use App\Tests\StravaExportFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Filesystem\Filesystem;

final class WebDashboardTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $workDir;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->workDir = sys_get_temp_dir().'/mystrava-web-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->workDir);
        parent::tearDown();
    }

    public function testEmptyDashboardInvitesToImport(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Aucune activité');
    }

    public function testUploadedExportFeedsDashboardAndActivities(): void
    {
        $this->uploadExport();

        self::assertResponseRedirects('/import');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Import terminé : 36 nouvelle(s)');

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.stat-grid', 'Indice de forme');
        self::assertSelectorCount(26, '.chart-column');
        self::assertSelectorTextContains('body', 'Marathon');

        $this->client->request('GET', '/activites?sport=run');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', '(36)');
        self::assertSelectorTextContains('table', 'Séance seuil');
    }

    public function testActivityDetailShowsSplitsFromFitTrack(): void
    {
        // Archive avec CSV + trace FIT rattachée par « Nom du fichier » (colonne Filename).
        $csvPath = $this->workDir.'/activities.csv';
        (new Filesystem())->mkdir($this->workDir);
        $handle = fopen($csvPath, 'w');
        \assert(false !== $handle);
        fputcsv($handle, StravaExportFactory::HEADER, escape: '');
        $start = (new \DateTimeImmutable('-3 days'))->setTime(7, 0);
        fputcsv($handle, ['777', $start->setTimezone(new \DateTimeZone('UTC'))->format('M j, Y, g:i:s A'), 'Fractionné', 'Run', '', 1300, '3.50', 180, 40, 'false', 'activities/777.fit.gz', 1300, 1250, 3500, 10, 150], escape: '');
        fclose($handle);

        $builder = (new FitFileBuilder())->session(1);
        $distance = 0.0;
        for ($t = 0; $t <= 1250; ++$t) {
            $builder->record($start->getTimestamp() + $t, $distance, 150);
            // 2e km rapide (4 m/s), les autres à 2,6 m/s.
            $distance += $distance >= 1000 && $distance < 2000 ? 4.0 : 2.6;
        }

        $zipPath = $this->workDir.'/export_777.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFile($csvPath, 'activities.csv');
        $zip->addFromString('activities/777.fit.gz', (string) gzencode($builder->build()));
        $zip->close();

        $crawler = $this->client->request('GET', '/import');
        $form = $crawler->selectButton('Importer')->form();
        $this->fileField($form)->upload($zipPath);
        $this->client->submit($form);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', '1 temps de passage calculé(s)');

        $this->client->request('GET', '/activites/777');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Fractionné');
        self::assertSelectorCount(4, '.split-table tbody tr');
        self::assertSelectorTextContains('.split-table tbody tr:nth-child(2)', 'rapide');
        self::assertSelectorTextContains('.split-table tbody tr:nth-child(2)', '4:10/km');
    }

    public function testUnsupportedFileIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/import');
        $path = $this->workDir.'/notes.txt';
        (new Filesystem())->dumpFile($path, 'pas une trace');

        $form = $crawler->selectButton('Importer')->form();
        $this->fileField($form)->upload($path);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Formats acceptés');
    }

    public function testGoalFormValidatesInput(): void
    {
        $crawler = $this->client->request('GET', '/objectif');
        $this->client->submit($crawler->selectButton('Calculer')->form([
            'goal[distance]' => '10k',
            'goal[time]' => 'vite',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Temps « vite » non reconnu');
    }

    public function testGoalPredictionWithAiAnalysis(): void
    {
        $this->uploadExport();

        $crawler = $this->client->request('GET', '/objectif');
        $form = $crawler->selectButton('Calculer + analyse IA')->form([
            'goal[distance]' => '10k',
            'goal[time]' => '45:00',
            'goal[date]' => (new \DateTimeImmutable('+10 weeks'))->format('Y-m-d'),
        ]);
        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertAnySelectorTextContains('h2', '10 km en 45:00');
        self::assertSelectorTextContains('.probability-label', 'modèle statistique');
        self::assertSelectorTextContains('.analysis', '58');
        self::assertSelectorTextContains('.analysis', 'Une séance de seuil par semaine');
    }

    public function testLastGoalIsRemembered(): void
    {
        $date = (new \DateTimeImmutable('+8 weeks'))->format('Y-m-d');
        $crawler = $this->client->request('GET', '/objectif');
        $this->client->submit($crawler->selectButton('Calculer')->form([
            'goal[distance]' => 'semi',
            'goal[time]' => '1:45:00',
            'goal[date]' => $date,
        ]));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/objectif');
        self::assertInputValueSame('goal[distance]', 'semi');
        self::assertInputValueSame('goal[time]', '1:45:00');
        self::assertInputValueSame('goal[date]', $date);
    }

    public function testTrainingPlanFollowsTheLastGoal(): void
    {
        $this->client->request('GET', '/programme');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Aucun objectif enregistré');

        $this->uploadExport();
        $crawler = $this->client->request('GET', '/objectif');
        $this->client->submit($crawler->selectButton('Calculer')->form([
            'goal[distance]' => '10k',
            'goal[time]' => '45:00',
            'goal[date]' => (new \DateTimeImmutable('+10 weeks'))->format('Y-m-d'),
        ]));

        $crawler = $this->client->request('GET', '/programme');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.intro', '10 km en 45:00');
        self::assertSelectorTextContains('details[open] summary', 'cette semaine');
        self::assertSelectorTextContains('main', 'Jour J');
        self::assertSelectorTextContains('main', '4:30/km');

        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'plan_settings[planSessionsPerWeek]' => '4',
            'plan_settings[clubWeekday]' => '3',
            'plan_settings[clubTime]' => '18:40',
        ]));
        self::assertResponseRedirects('/programme');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Réglages du programme enregistrés');
        self::assertSelectorExists('#plan_settings_planSessionsPerWeek option[value="4"][selected]');
        self::assertSelectorTextContains('details:nth-of-type(2) table', 'Séance club');
        self::assertSelectorTextContains('details:nth-of-type(2) table', 'À 18:40');
        self::assertSelectorTextContains('details:nth-of-type(2) table', 'mer.');

        // Semaine 2 sans club et avec 3 sorties.
        $form = $crawler->filter('details')->eq(1)->selectButton('Ajuster la semaine')->form();
        $club = $form['club'];
        self::assertInstanceOf(ChoiceFormField::class, $club);
        $club->untick();
        $sessions = $form['sessions'];
        self::assertInstanceOf(ChoiceFormField::class, $sessions);
        $sessions->select('3');
        $this->client->submit($form);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        $week = $crawler->filter('details')->eq(1);
        self::assertStringContainsString('ajustée', $week->filter('summary')->text());
        self::assertStringNotContainsString('Séance club', $week->filter('table')->text());
        self::assertCount(3, $week->filter('tbody tr'));

        $this->client->submit($week->selectButton('Revenir au réglage général')->form());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Séance club', $crawler->filter('details')->eq(1)->filter('table')->text());
        self::assertCount(4, $crawler->filter('details')->eq(1)->filter('tbody tr'));
    }

    public function testWeekAdjustmentRequiresAValidToken(): void
    {
        $this->client->request('POST', '/programme/semaine/2026-10-12', ['_token' => 'faux', 'sessions' => '2']);

        // Refus d'accès (401 tant que l'application n'a pas d'authentification), rien n'est enregistré.
        self::assertTrue($this->client->getResponse()->isClientError());
        self::assertSame(0, static::getContainer()->get(TrainingWeekOverrideRepository::class)->count([]));
    }

    private function uploadExport(): void
    {
        $csv = StravaExportFactory::create($this->workDir.'/export');
        $zipPath = $this->workDir.'/export_42.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFile($csv, 'activities.csv');
        $zip->close();

        $crawler = $this->client->request('GET', '/import');
        $form = $crawler->selectButton('Importer')->form();
        $this->fileField($form)->upload($zipPath);
        $this->client->submit($form);
    }

    private function fileField(Form $form): FileFormField
    {
        $field = $form['import[file]'];
        self::assertInstanceOf(FileFormField::class, $field);

        return $field;
    }
}
