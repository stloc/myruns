<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Elevation\ElevationCorrector;
use App\Elevation\IgnAltimetry;
use App\Entity\Activity;
use App\Enum\SplitKind;
use App\Enum\Sport;
use App\Import\ActivityImporter;
use App\Import\StravaCsvParser;
use App\Import\Track\SplitCalculator;
use App\Import\TrackFileParser;
use App\Repository\ActivityRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * D+ recalculé sur l'altitude du terrain (faux service IGN) à l'import d'une trace GPS.
 */
final class GroundElevationTest extends KernelTestCase
{
    private string $workDir;
    private int $requests = 0;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir().'/myruns-elevation-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->workDir);
        parent::tearDown();
    }

    public function testGroundElevationReplacesWatchElevationAndReimportIsOffline(): void
    {
        self::bootKernel();
        $importer = $this->importer();
        $path = $this->writeGpx();

        $first = $importer->import($path);

        self::assertSame(1, $first->created);
        self::assertSame(1, $first->groundElevation);
        self::assertSame(1, $this->requests);
        $activity = self::getContainer()->get(ActivityRepository::class)->find('file:trail.gpx');
        self::assertInstanceOf(Activity::class, $activity);
        self::assertSame(Sport::TrailRun, $activity->sport, 'Trail reconnu par le nom de la trace');
        self::assertSame(0.0, $activity->elevationGain, 'Altitude de la montre conservée telle quelle');
        // 50 m de montée, sommet un peu arrondi par le lissage.
        self::assertEqualsWithDelta(48.5, $activity->groundElevationGain, 1.5);
        self::assertSame($activity->groundElevationGain, $activity->effectiveElevationGain());
        $kilometers = $activity->splitsOf(SplitKind::Kilometer);
        self::assertCount(2, $kilometers);
        self::assertEqualsWithDelta($activity->groundElevationGain, ($kilometers[0]->elevationGain ?? 0) + ($kilometers[1]->elevationGain ?? 0), 1.0, 'D+ au km tiré du terrain');

        // Réimport : altitudes servies par le cache, rien ne change.
        $second = $importer->import($path);

        self::assertSame(1, $second->unchanged);
        self::assertSame(0, $second->withSplits);
        self::assertSame(1, $this->requests);
    }

    private function importer(): ActivityImporter
    {
        // Le terrain monte de 50 m sur les 1000 premiers mètres, puis redescend.
        $client = new MockHttpClient(function (string $method, string $url, array $options): JsonMockResponse {
            ++$this->requests;
            /** @var array{lat: string} $body */
            $body = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '', true, flags: \JSON_THROW_ON_ERROR);
            $elevations = array_map(static function (string $lat): float {
                $distance = ((float) $lat - 48) * 111195;

                return $distance <= 1000 ? 20 + $distance / 20 : 70 - ($distance - 1000) / 20;
            }, explode('|', $body['lat']));

            return new JsonMockResponse(['elevations' => $elevations]);
        }, 'https://data.geopf.fr');

        $container = self::getContainer();

        return new ActivityImporter(
            $container->get(ActivityRepository::class),
            new StravaCsvParser(),
            new TrackFileParser(),
            new SplitCalculator(),
            new ElevationCorrector(new IgnAltimetry($client, new ArrayAdapter())),
            $this->workDir,
        );
    }

    /**
     * 2 km plein nord à 3 m/s ; la montre (sans baromètre) enregistre une altitude plate.
     */
    private function writeGpx(): string
    {
        $points = '';
        for ($d = 0; $d <= 2000; $d += 6) {
            $points .= \sprintf('<trkpt lat="%.7f" lon="-4.0"><ele>10</ele><time>%s</time></trkpt>', 48 + $d / 111195, gmdate('Y-m-d\TH:i:s\Z', 1767600000 + intdiv($d, 3)));
        }
        $path = $this->workDir.'/trail.gpx';
        file_put_contents($path, '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><trk><name>Trail du midi</name><type>running</type><trkseg>'.$points.'</trkseg></trk></gpx>');

        return $path;
    }
}
