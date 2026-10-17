<?php

declare(strict_types=1);

namespace App\Tests\Unit\Elevation;

use App\Elevation\ElevationCorrector;
use App\Elevation\IgnAltimetry;
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

    public function testOutsideFranceKeepsWatchAltitudeWithoutRetrying(): void
    {
        $corrector = $this->corrector(static fn (float $distance): float => $distance > 100 ? -99999.0 : 10.0);

        self::assertNull($corrector->correct($this->track(300)));
        self::assertNull($corrector->correct($this->track(300)));
        self::assertCount(1, $this->requests, 'Zone non couverte mise en cache');
    }

    public function testUnavailableServiceIsRetriedAtNextImport(): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 503]);
        }, 'https://data.geopf.fr');
        $corrector = new ElevationCorrector(new IgnAltimetry($client, new ArrayAdapter()));

        self::assertNull($corrector->correct($this->track(300)));
        self::assertNull($corrector->correct($this->track(300)));
        self::assertSame(2, $calls);
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
     * Faux service IGN : l'altitude dépend de la distance parcourue (1 m de latitude ≈ 1/111195 °).
     *
     * @param \Closure(float): float $profile altitude selon la distance depuis le départ
     */
    private function corrector(\Closure $profile): ElevationCorrector
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

        return new ElevationCorrector(new IgnAltimetry($client, new ArrayAdapter()));
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
