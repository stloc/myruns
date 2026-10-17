<?php

declare(strict_types=1);

namespace App\Tests\Unit\Elevation;

use App\Elevation\ElevationCorrector;
use App\Elevation\IgnAltimetry;
use App\Elevation\OpenMeteoElevation;
use App\Entity\Activity;
use App\Enum\Sport;
use App\Import\Track\ParsedTrack;
use App\Import\Track\TrackPoint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ElevationCorrectorTest extends TestCase
{
    /** @var list<array{lat: list<string>, lon: list<string>}> requêtes reçues par le faux service IGN */
    private array $requests = [];
    /** @var list<list<string>> latitudes demandées au faux Open-Meteo */
    private array $worldRequests = [];

    public function testReplacesWatchAltitudeByGroundAltitude(): void
    {
        // Montée régulière de 20 m sur 400 m puis descente : la montre, elle, n'a rien vu.
        $corrector = $this->corrector(static fn (float $distance): float => $distance <= 400 ? 50 + $distance / 20 : 70 - ($distance - 400) / 20);

        $corrected = $corrector->correct($this->track(800));

        self::assertNotNull($corrected);
        self::assertCount(1, $this->requests);
        self::assertCount(81, $this->requests[0]['lat'], 'Un point tous les 10 m');
        // Moyenne glissante : le sommet est un peu arrondi.
        self::assertEqualsWithDelta(19.0, $corrected->groundElevationGain, 1.0);
        self::assertEqualsWithDelta(50.5, $corrected->points[0]->altitudeMeters, 0.01, 'Moyenne des 3 premiers échantillons');
        self::assertEqualsWithDelta(54.1, $corrected->points[41]->altitudeMeters, 0.01, '82 m : interpolée entre les échantillons de 80 et 90 m');
        self::assertSame(4.0, $this->track(800)->points[41]->altitudeMeters, 'La trace d\'origine n\'est pas modifiée');
    }

    public function testSameTrackIsServedFromCache(): void
    {
        $corrector = $this->corrector(static fn (float $distance): float => 10.0);

        $corrector->correct($this->track(300));
        $corrector->correct($this->track(300));

        self::assertCount(1, $this->requests);
    }

    public function testPointsOutsideIgnCoverageAreCompletedWorldwide(): void
    {
        // Sortie qui quitte la zone IGN (frontière, mer) après 100 m : le reste vient d'Open-Meteo.
        $corrector = $this->corrector(static fn (float $distance): float => $distance > 100 ? -99999.0 : 10.0, static fn (float $distance): float => 10.0 + ($distance - 100) / 10);

        $corrected = $corrector->correct($this->track(300));

        self::assertNotNull($corrected);
        self::assertCount(1, $this->worldRequests);
        // 20 échantillons hors zone (110 à 300 m) : 1 sur 5 et le dernier, les autres interpolés.
        self::assertCount(5, $this->worldRequests[0]);
        self::assertSame(sprintf('%.5F', 48 + 110 / 111195), $this->worldRequests[0][0]);
        self::assertEqualsWithDelta(19.0, $corrected->groundElevationGain, 1.0);

        $corrector->correct($this->track(300));
        self::assertCount(1, $this->requests, 'IGN en cache');
        self::assertCount(1, $this->worldRequests, 'Open-Meteo en cache');
    }

    public function testAbroadTrackComesFromOpenMeteoEvery50MetersByBatchesOf100(): void
    {
        $corrector = $this->corrector(static fn (float $distance): float => -99999.0, static fn (float $distance): float => 600.0 + $distance / 20);

        $corrected = $corrector->correct($this->track(6000));

        self::assertNotNull($corrected);
        // 601 échantillons (un tous les 10 m), 121 demandés (tous les 50 m), par lots de 100.
        self::assertSame([100, 21], array_map(count(...), $this->worldRequests));
        self::assertEqualsWithDelta(300.0, $corrected->groundElevationGain, 1.0);
    }

    public function testAbroadWithoutOpenMeteoKeepsWatchAltitudeAndRetriesLater(): void
    {
        $corrector = $this->corrector(static fn (float $distance): float => -99999.0);

        self::assertNull($corrector->correct($this->track(300)));
        self::assertNull($corrector->correct($this->track(300)));
        self::assertCount(1, $this->requests, 'Zone non couverte par l\'IGN mise en cache');
        self::assertCount(2, $this->worldRequests, 'Open-Meteo redemandé');
    }

    public function testOldCacheEntryOutsideIgnCoverageIsCompletedWorldwide(): void
    {
        // Avant le complément mondial, l'IGN mettait null en cache pour toute la trace dès qu'un point était hors zone.
        $cache = new ArrayAdapter();
        $latitudes = [];
        for ($d = 0; $d <= 300; $d += 10) {
            $latitudes[] = \sprintf('%.6F', 48 + $d / 111195);
        }
        $key = 'ign_'.hash('xxh128', implode('|', $latitudes).'/'.implode('|', array_fill(0, \count($latitudes), '-4.000000')));
        $cache->get($key, static fn (): ?array => null);
        $corrector = $this->corrector(static fn (float $distance): float => 10.0, static fn (float $distance): float => 42.0, $cache);

        self::assertNotNull($corrector->correct($this->track(300)));
        self::assertSame([], $this->requests, 'IGN servi par le cache');
        self::assertCount(7, $this->worldRequests[0], 'Toute la trace demandée à Open-Meteo (1 point sur 5)');
    }

    public function testUnavailableServiceIsRetriedAtNextImport(): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 503]);
        }, 'https://data.geopf.fr');
        $corrector = new ElevationCorrector(new IgnAltimetry($client, new ArrayAdapter()), $this->openMeteo(null));

        self::assertNull($corrector->correct($this->track(300)));
        self::assertNull($corrector->correct($this->track(300)));
        self::assertSame(2, $calls);
        self::assertSame([], $this->worldRequests, 'IGN injoignable : Open-Meteo ne le remplace pas en France');
    }

    public function testServiceDownIsNoLongerCalledAfterThreeFailures(): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 503]);
        }, 'https://data.geopf.fr');
        $corrector = new ElevationCorrector(new IgnAltimetry($client, new ArrayAdapter()), $this->openMeteo(null));

        for ($meters = 300; $meters < 400; $meters += 10) {
            self::assertNull($corrector->correct($this->track($meters)));
        }
        self::assertSame(3, $calls);
    }

    public function testTrackWithoutPositionIsNotSent(): void
    {
        $corrector = $this->corrector(static fn (float $distance): float => 10.0);
        $points = array_map(static fn (TrackPoint $p): TrackPoint => new TrackPoint($p->timestamp, $p->distanceMeters, null, 4.0), $this->track(300)->points);

        self::assertNull($corrector->correct(new ParsedTrack($this->track(300)->activity, $points)));
        self::assertSame([], $this->requests);
    }

    public function testGainIgnoresNoiseBelowThreshold(): void
    {
        self::assertSame(0.0, ElevationCorrector::gain([10.0, 10.5, 10.2, 10.8, 10.4]));
        self::assertSame(5.0, ElevationCorrector::gain([10.0, 12.0, 11.5, 15.0, 13.0]));
    }

    /**
     * Faux services IGN et Open-Meteo : l'altitude dépend de la distance parcourue (1 m de latitude ≈ 1/111195 °).
     *
     * @param \Closure(float): float        $profile      altitude IGN selon la distance depuis le départ (-99999 hors zone)
     * @param (\Closure(float): float)|null $worldProfile altitude Open-Meteo, null pour un service indisponible
     */
    private function corrector(\Closure $profile, ?\Closure $worldProfile = null, ?ArrayAdapter $cache = null): ElevationCorrector
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($profile): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://data.geopf.fr/altimetrie/1.0/calcul/alti/rest/elevation.json', $url);
            /** @var array{lat: string, lon: string} $body */
            $body = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '', true, flags: \JSON_THROW_ON_ERROR);
            $lat = explode('|', $body['lat']);
            $this->requests[] = ['lat' => $lat, 'lon' => explode('|', $body['lon'])];

            return new JsonMockResponse(['elevations' => array_map(static fn (string $l): float => $profile(round(((float) $l - 48) * 111195)), $lat)]);
        }, 'https://data.geopf.fr');

        $cache ??= new ArrayAdapter();

        return new ElevationCorrector(new IgnAltimetry($client, $cache), $this->openMeteo($worldProfile, $cache));
    }

    /**
     * @param (\Closure(float): float)|null $profile altitude selon la distance, null pour un service indisponible
     */
    private function openMeteo(?\Closure $profile, ?ArrayAdapter $cache = null): OpenMeteoElevation
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($profile): MockResponse {
            self::assertSame('GET', $method);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            self::assertIsString($query['latitude'] ?? null);
            $latitudes = explode(',', $query['latitude']);
            $this->worldRequests[] = $latitudes;
            if (null === $profile) {
                return new MockResponse('', ['http_code' => 503]);
            }

            return new JsonMockResponse(['elevation' => array_map(static fn (string $l): float => $profile(round(((float) $l - 48) * 111195)), $latitudes)]);
        }, 'https://api.open-meteo.com');

        return new OpenMeteoElevation($client, $cache ?? new ArrayAdapter(), minIntervalSeconds: 0.0);
    }

    /**
     * Ligne droite vers le nord, un point par seconde à 2 m/s, altitude de la montre fausse (4 m).
     */
    private function track(int $meters): ParsedTrack
    {
        $points = [];
        for ($d = 0; $d <= $meters; $d += 2) {
            $points[] = new TrackPoint(1767600000 + intdiv($d, 2), (float) $d, 150, 4.0, 48 + $d / 111195, -4.0);
        }
        $activity = new Activity('file:test.gpx', 'track_file', 'Test', Sport::Run, new \DateTimeImmutable('@1767600000'), $meters, intdiv($meters, 2), intdiv($meters, 2), 0.0);

        return new ParsedTrack($activity, $points);
    }
}
