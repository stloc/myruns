<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Activity;
use App\Entity\ActivityRoute;
use App\Import\ActivityImporter;
use App\Repository\ActivityRepository;
use App\Tests\StravaExportFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Trace GPS simplifiée conservée à l'import, pour la carte de la page détail.
 */
final class ActivityRouteTest extends KernelTestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->workDir = sys_get_temp_dir().'/myruns-route-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->workDir);
        parent::tearDown();
    }

    public function testImportKeepsTheRouteAndReimportChangesNothing(): void
    {
        $importer = self::getContainer()->get(ActivityImporter::class);
        $path = $this->writeGpx('sortie.gpx', 'running');

        $first = $importer->import($path);
        self::assertSame(1, $first->withSplits);

        $this->entityManager()->clear();
        $route = $this->activity('file:sortie.gpx')->routePoints();
        self::assertCount(42 + 1, $route, 'Relevés tous les 6 m : un point tous les 24 m, plus l\'arrivée');
        self::assertSame([48.0, -4.0, 0.0, 0], $route[0]);
        self::assertSame([round(48 + 1000 / 111195, 5), -4.0, 1000.0, 334], $route[42]);

        $second = $importer->import($path);
        self::assertSame(1, $second->unchanged);
        self::assertSame(0, $second->withSplits);
        self::assertSame(1, $this->routeCount());
    }

    public function testNoRouteForSwimming(): void
    {
        self::getContainer()->get(ActivityImporter::class)->import($this->writeGpx('piscine.gpx', 'swimming'));

        self::assertSame([], $this->activity('file:piscine.gpx')->routePoints());
        self::assertSame(0, $this->routeCount());
    }

    public function testRouteIsRemovedWhenTheCsvReplacesTheTrack(): void
    {
        $importer = self::getContainer()->get(ActivityImporter::class);
        $importer->import($this->writeGpx('sortie.gpx', 'running'));
        self::assertSame(1, $this->routeCount());

        // Même sortie dans le CSV de l'archive, sans fichier de trace : la ligne du CSV remplace la trace.
        $csv = $this->workDir.'/export/activities.csv';
        (new Filesystem())->mkdir(\dirname($csv));
        $handle = fopen($csv, 'w');
        \assert(false !== $handle);
        fputcsv($handle, StravaExportFactory::HEADER, escape: '');
        fputcsv($handle, ['555', 'Jan 5, 2026, 8:00:00 AM', 'Sortie', 'Run', '', 334, '1.00', 160, 10, 'false', '', 334, 334, 1000, 0, 150], escape: '');
        fclose($handle);
        $importer->import(\dirname($csv));

        $this->entityManager()->clear();
        self::assertNull(self::getContainer()->get(ActivityRepository::class)->find('file:sortie.gpx'));
        self::assertSame([], $this->activity('555')->routePoints());
        self::assertSame(0, $this->routeCount(), 'Pas de trace orpheline');
    }

    private function activity(string $id): Activity
    {
        $activity = self::getContainer()->get(ActivityRepository::class)->find($id);
        self::assertInstanceOf(Activity::class, $activity);

        return $activity;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function routeCount(): int
    {
        return $this->entityManager()->getRepository(ActivityRoute::class)->count();
    }

    /**
     * 1 km plein nord à 3 m/s, un point tous les 6 m, départ le 5 janvier 2026 à 8 h UTC.
     */
    private function writeGpx(string $name, string $type): string
    {
        $points = '';
        for ($d = 0; $d <= 1000; $d += 6) {
            $points .= \sprintf('<trkpt lat="%.7f" lon="-4.0"><time>%s</time></trkpt>', 48 + $d / 111195, gmdate('Y-m-d\TH:i:s\Z', 1767600000 + intdiv($d, 3)));
        }
        $points .= \sprintf('<trkpt lat="%.7f" lon="-4.0"><time>%s</time></trkpt>', 48 + 1000 / 111195, gmdate('Y-m-d\TH:i:s\Z', 1767600334));
        $path = $this->workDir.'/'.$name;
        file_put_contents($path, '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><trk><name>Sortie</name><type>'.$type.'</type><trkseg>'.$points.'</trkseg></trk></gpx>');

        return $path;
    }
}
