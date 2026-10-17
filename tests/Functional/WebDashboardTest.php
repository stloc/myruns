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
        $this->workDir = sys_get_temp_dir().'/myruns-web-'.bin2hex(random_bytes(4));
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

    public function testActivitySearch(): void
    {
        $this->uploadExport();

        $this->client->request('GET', '/activites?q=sortie');
        self::assertSelectorTextContains('h1', '(12)');
        self::assertSelectorTextNotContains('table', 'Footing');

        $this->client->request('GET', '/activites?sport=course&km_min=10');
        self::assertSelectorTextContains('h1', '(12)');
        self::assertSelectorTextContains('table', 'Sortie longue');

        // Tri par allure : la séance seuil (4:40/km) arrive en tête.
        $this->client->request('GET', '/activites?tri=allure');
        self::assertSelectorTextContains('tbody tr:first-child', 'Séance seuil');
        self::assertSelectorExists('select[name="tri"] option[value="allure"][selected]');

        $this->client->request('GET', '/activites?q=s%C3%A9ance&sport=trail_run');
        self::assertSelectorTextContains('h1', '(0)');
        self::assertSelectorTextContains('body', 'Aucune activité ne correspond');
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
        self::assertSelectorNotExists('[data-route-map]', 'Pas de carte sans positions GPS');
    }

    public function testActivityDetailShowsTheRouteMap(): void
    {
        // 1 km plein nord : 500 m à 4:00/km puis 500 m à 6:00/km.
        $points = '';
        $time = 1767600000;
        for ($d = 0; $d <= 1000; $d += 10) {
            $points .= \sprintf('<trkpt lat="%.7f" lon="-4.0"><time>%s</time></trkpt>', 48 + $d / 111195, gmdate('Y-m-d\TH:i:s\Z', (int) round($time)));
            $time += $d < 500 ? 2.4 : 3.6;
        }
        (new Filesystem())->mkdir($this->workDir);
        $path = $this->workDir.'/carte.gpx';
        file_put_contents($path, '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><trk><name>Footing carte</name><type>running</type><trkseg>'.$points.'</trkseg></trk></gpx>');

        $crawler = $this->client->request('GET', '/import');
        $form = $crawler->selectButton('Importer')->form();
        $this->fileField($form)->upload($path);
        $this->client->submit($form);

        // Le fichier envoyé est renommé : on ouvre l'activité depuis la liste.
        $crawler = $this->client->request('GET', '/activites');
        $crawler = $this->client->click($crawler->selectLink('Footing carte')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#route-map-title', 'Parcours');
        /** @var array{segments: list<array{class: string, label: string}>, bounds: list<list<float>>} $route */
        $route = json_decode((string) $crawler->filter('[data-route-map]')->attr('data-route-map'), true, flags: \JSON_THROW_ON_ERROR);
        // Tronçons de 100 m ; le dernier, raccourci par l'arrondi de la distance GPS, rejoint le précédent.
        self::assertCount(9, $route['segments']);
        self::assertSame('pace-1', $route['segments'][0]['class']);
        // Deux allures seulement : les tronçons ex æquo partagent la même classe.
        self::assertGreaterThan('pace-1', $route['segments'][8]['class']);
        self::assertStringEndsWith('/km', $route['segments'][8]['label']);
        self::assertSelectorTextContains('.route-legend li:first-child', '/km');
    }

    public function testFolderImportIsHiddenWithoutConfiguredFolder(): void
    {
        // STRAVA_EXPORT_PATH vide (comme dans l'app de bureau) : seul l'envoi de fichier est proposé.
        $this->client->request('GET', '/import');

        self::assertSelectorTextContains('main', 'Envoyer un fichier');
        self::assertSelectorTextNotContains('main', 'Importer le dossier');
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

        // Bilan prévu / réalisé : la semaine passée a forcément des séances faites (3 sorties par semaine).
        self::assertSelectorTextContains('.plan-review h2', 'Bilan et conseils');
        self::assertSelectorTextContains('.plan-review', 'Probabilité de réussir l\'objectif');
        self::assertSelectorExists('#semaine-passee .plan-review-table .badge-success');
        $activityPage = $crawler->filter('#semaine-passee .plan-review-table a')->first()->link();
        $this->client->click($activityPage);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Séance du programme');
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('main', 'Dernière séance du programme');
        $crawler = $this->client->request('GET', '/programme');

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

    public function testLongImportAdvancesStepByStep(): void
    {
        // En test, une trace par étape (app.import_step_seconds = 0).
        $this->uploadExport(tracks: 3);

        // 1ʳᵉ étape dans la requête d'envoi : CSV + 1ʳᵉ trace, puis page d'avancement.
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/import/[0-9a-f]{16}$#', $location);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-import-label]', '1 / 3 traces (33 %)');

        $this->client->request('GET', '/import');
        self::assertSelectorTextContains('body', 'Import inachevé');
        self::assertSelectorTextContains('body', '1 trace(s) lue(s) sur 3');

        // Étape demandée par la page (fetch) : avancement en JSON.
        $continue = $crawler->selectButton('Continuer')->form();
        $this->client->submit($continue, [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        self::assertSame(['done' => 2, 'total' => 3, 'percent' => 66], json_decode((string) $this->client->getResponse()->getContent(), true));

        // Sans JavaScript (bouton « Continuer ») : dernière trace, retour à la page Import.
        $this->client->submit($continue);
        self::assertResponseRedirects('/import');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Import terminé : 39 nouvelle(s)');
        self::assertSelectorTextNotContains('body', 'Import inachevé');
        self::assertFileDoesNotExist($this->uploadDir().'/'.basename($location).'.zip', 'Archive supprimée une fois l\'import terminé');

        // Page d'un import terminé (rechargement, autre onglet) : retour à la page Import.
        $this->client->request('GET', $location);
        self::assertResponseRedirects('/import');
        $this->client->submit($continue, [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(['redirect' => '/import'], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testAbandonedImportKeepsImportedActivities(): void
    {
        $this->uploadExport(tracks: 2);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $crawler = $this->client->followRedirect();

        $this->client->submit($crawler->selectButton('Arrêter')->form());
        self::assertResponseRedirects('/import');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-warning', 'Import interrompu (1 trace(s) lue(s) sur 2)');
        self::assertSelectorTextNotContains('body', 'Import inachevé');
        self::assertFileDoesNotExist($this->uploadDir().'/'.basename($location).'.zip');

        $this->client->request('GET', '/activites');
        self::assertSelectorTextContains('h1', '(37)');
    }

    private function uploadDir(): string
    {
        $dataDir = static::getContainer()->getParameter('app.data_dir');
        self::assertIsString($dataDir);

        return $dataDir.'/uploads';
    }

    /**
     * @param int $tracks traces GPX ajoutées à l'archive (sorties absentes du CSV)
     */
    private function uploadExport(int $tracks = 0): void
    {
        $csv = StravaExportFactory::create($this->workDir.'/export');
        $zipPath = $this->workDir.'/export_42.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFile($csv, 'activities.csv');
        for ($i = 1; $i <= $tracks; ++$i) {
            $points = '';
            $start = (new \DateTimeImmutable('2020-01-01 08:00 UTC'))->modify(\sprintf('+%d days', $i))->getTimestamp();
            for ($d = 0; $d <= 500; $d += 10) {
                $points .= \sprintf('<trkpt lat="%.7f" lon="-4.0"><time>%s</time></trkpt>', 48 + $d / 111195, gmdate('Y-m-d\TH:i:s\Z', $start + intdiv(3 * $d, 10)));
            }
            $zip->addFromString(\sprintf('activities/trace%d.gpx', $i), '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><trk><name>Trace '.$i.'</name><type>running</type><trkseg>'.$points.'</trkseg></trk></gpx>');
        }
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
