<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Elevation\ElevationCorrector;
use App\Elevation\IgnAltimetry;
use App\Elevation\OpenMeteoElevation;
use App\Entity\Activity;
use App\Enum\SplitKind;
use App\Enum\Sport;
use App\Geocoding\CityFinder;
use App\Geocoding\IgnReverseGeocoder;
use App\Geocoding\NominatimReverseGeocoder;
use App\Import\ActivityImporter;
use App\Import\StravaCsvParser;
use App\Import\Track\SplitCalculator;
use App\Import\TrackFileParser;
use App\Repository\ActivityRepository;
use App\Repository\ActivitySearch;
use App\Weather\OpenMeteoWeather;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * D+ recalculé sur l'altitude du terrain (faux service IGN), commune et météo (faux Open-Meteo) à
 * l'import d'une trace GPS.
 */
final class GroundElevationTest extends KernelTestCase
{
    private string $workDir;
    private int $requests = 0;
    /** @var list<string> requêtes reçues par le faux historique météo */
    private array $weatherRequests = [];

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
        self::assertSame(2, $this->requests, 'Une requête d\'altimétrie, une de géocodage');
        $activity = self::getContainer()->get(ActivityRepository::class)->find('file:trail.gpx');
        self::assertInstanceOf(Activity::class, $activity);
        self::assertSame(Sport::TrailRun, $activity->sport, 'Trail reconnu par le nom de la trace');
        self::assertSame(0.0, $activity->elevationGain, 'Altitude de la montre conservée telle quelle');
        // 50 m de montée, sommet un peu arrondi par le lissage.
        self::assertEqualsWithDelta(48.5, $activity->groundElevationGain, 1.5);
        self::assertSame($activity->groundElevationGain, $activity->effectiveElevationGain());
        self::assertSame('Plouguerneau', $activity->city, 'Commune du point de départ');
        self::assertSame(1, self::getContainer()->get(ActivityRepository::class)->countMatching(ActivitySearch::fromQuery(['q' => 'plouguerneau'])), 'Recherche par ville');
        $kilometers = $activity->splitsOf(SplitKind::Kilometer);
        self::assertCount(2, $kilometers);
        self::assertEqualsWithDelta($activity->groundElevationGain, ($kilometers[0]->elevationGain ?? 0) + ($kilometers[1]->elevationGain ?? 0), 1.0, 'D+ au km tiré du terrain');
        $weather = $activity->weather();
        self::assertNotNull($weather, 'Météo au point de départ');
        self::assertSame(7.5, $weather->temperature, 'Heure la plus proche du milieu de la sortie (8 h 05)');
        self::assertSame('Pluie', $weather->conditionLabel());
        self::assertSame('SO', $weather->windDirectionLabel());
        self::assertSame(0.8, $weather->precipitation, 'Cumul de l\'heure couverte par la sortie');
        self::assertCount(1, $this->weatherRequests);
        self::assertStringContainsString('latitude=48.00&longitude=-4.00&start_date=2026-01-05&end_date=2026-01-05', $this->weatherRequests[0]);

        // Réimport : altitudes servies par le cache, rien ne change.
        $second = $importer->import($path);

        self::assertSame(1, $second->unchanged);
        self::assertSame(0, $second->withSplits);
        self::assertSame(2, $this->requests);
        self::assertCount(1, $this->weatherRequests);
    }

    private function importer(): ActivityImporter
    {
        // Le terrain monte de 50 m sur les 1000 premiers mètres, puis redescend.
        $client = new MockHttpClient(function (string $method, string $url, array $options): JsonMockResponse {
            ++$this->requests;
            if (str_contains($url, '/geocodage/reverse')) {
                return new JsonMockResponse(['type' => 'FeatureCollection', 'features' => [['properties' => ['city' => 'Plouguerneau', '_type' => 'parcel']]]]);
            }
            /** @var array{lat: string} $body */
            $body = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '', true, flags: \JSON_THROW_ON_ERROR);
            $elevations = array_map(static function (string $lat): float {
                $distance = ((float) $lat - 48) * 111195;

                return $distance <= 1000 ? 20 + $distance / 20 : 70 - ($distance - 1000) / 20;
            }, explode('|', $body['lat']));

            return new JsonMockResponse(['elevations' => $elevations]);
        }, 'https://data.geopf.fr');

        // Sortie en France : les services mondiaux ne doivent pas être appelés.
        $unused = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('Service mondial appelé pour une sortie en France.'));

        // Historique météo du 5 janvier 2026 (UTC) : pluie de 8 h à 9 h.
        $weather = new MockHttpClient(function (string $method, string $url): JsonMockResponse {
            $this->weatherRequests[] = $url;
            $hours = range(0, 23);

            return new JsonMockResponse(['hourly' => [
                'time' => array_map(static fn (int $h): int => 1767571200 + $h * 3600, $hours),
                'temperature_2m' => array_map(static fn (int $h): float => 8 === $h ? 7.5 : 5.0, $hours),
                'apparent_temperature' => array_fill(0, 24, 4.0),
                'relative_humidity_2m' => array_fill(0, 24, 90),
                'dew_point_2m' => array_fill(0, 24, 6.0),
                'precipitation' => array_map(static fn (int $h): float => 9 === $h ? 0.8 : 0.0, $hours),
                'weather_code' => array_map(static fn (int $h): int => 8 === $h ? 61 : 3, $hours),
                'wind_speed_10m' => array_fill(0, 24, 18.0),
                'wind_gusts_10m' => array_fill(0, 24, 35.0),
                'wind_direction_10m' => array_fill(0, 24, 225),
            ]]);
        }, 'https://archive-api.open-meteo.com');

        $container = self::getContainer();

        return new ActivityImporter(
            $container->get(ActivityRepository::class),
            new StravaCsvParser(),
            new TrackFileParser(),
            new SplitCalculator(),
            new ElevationCorrector(new IgnAltimetry($client, new ArrayAdapter()), new OpenMeteoElevation($unused, new ArrayAdapter(), minIntervalSeconds: 0.0)),
            new CityFinder(new IgnReverseGeocoder($client, new ArrayAdapter()), new NominatimReverseGeocoder($unused, new ArrayAdapter())),
            new OpenMeteoWeather($weather, $unused, new ArrayAdapter(), minIntervalSeconds: 0.0),
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
