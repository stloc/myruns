<?php

declare(strict_types=1);

namespace App\Tests\Unit\Weather;

use App\Weather\OpenMeteoWeather;
use App\Weather\Weather;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenMeteoWeatherTest extends TestCase
{
    /** 20 octobre 2026, 00 h 00 UTC. */
    private const DAY = 1792454400;

    /** @var list<string> */
    private array $archiveRequests = [];
    /** @var list<string> */
    private array $forecastRequests = [];

    public function testOldOutingComesFromArchiveAndIsCached(): void
    {
        $weather = $this->weather(now: '2026-11-15');
        $start = new \DateTimeImmutable('@'.(self::DAY + 7 * 3600 + 50 * 60));

        $first = $weather->during(48.3904, -4.4861, $start, 3600);
        $again = $weather->during(48.3897, -4.4855, $start, 3600);

        self::assertInstanceOf(Weather::class, $first);
        self::assertEquals($first, $again);
        self::assertCount(1, $this->archiveRequests, 'Même jour, même lieu à ~1 km près : servi par le cache');
        self::assertSame([], $this->forecastRequests);
        self::assertStringContainsString('latitude=48.39&longitude=-4.49', $this->archiveRequests[0]);
        self::assertStringContainsString('timeformat=unixtime', $this->archiveRequests[0]);
        // 7 h 50 → 8 h 50 : heure la plus proche du milieu (8 h 20) = 8 h.
        self::assertSame(8.0, $first->temperature);
        self::assertSame(2, $first->code);
        self::assertSame('Partiellement nuageux', $first->conditionLabel());
        self::assertSame('cloud-sun', $first->conditionIcon());
        self::assertSame(80, $first->humidity);
        self::assertSame(20.0, $first->windSpeed);
        self::assertSame('O', $first->windDirectionLabel());
        // Heures de 7 h à 8 h et de 8 h à 9 h (cumuls lus à 8 h et 9 h) : 0,8 + 0,9 mm.
        self::assertSame(1.7, $first->precipitation);
    }

    public function testRecentOutingComesFromForecastApi(): void
    {
        $weather = $this->weather(now: '2026-10-22');

        $result = $weather->during(48.39, -4.49, new \DateTimeImmutable('@'.(self::DAY + 18 * 3600)), 1800);

        self::assertNotNull($result);
        self::assertSame(18.0, $result->temperature);
        self::assertSame([], $this->archiveRequests);
        self::assertCount(1, $this->forecastRequests);
        self::assertStringStartsWith('https://api.open-meteo.com/v1/forecast?', $this->forecastRequests[0]);
    }

    public function testOutingAcrossMidnightRequestsBothDays(): void
    {
        $weather = $this->weather(now: '2026-11-15');

        $weather->during(48.39, -4.49, new \DateTimeImmutable('@'.(self::DAY + 23 * 3600 + 30 * 60)), 3600);

        self::assertStringContainsString('start_date=2026-10-20&end_date=2026-10-21', $this->archiveRequests[0]);
    }

    public function testHoursNotYetPublishedAreNotCached(): void
    {
        $weather = $this->weather(now: '2026-11-15', missingHours: true);
        $start = new \DateTimeImmutable('@'.(self::DAY + 8 * 3600));

        self::assertNull($weather->during(48.39, -4.49, $start, 3600));
        self::assertNull($weather->during(48.39, -4.49, $start, 3600));
        self::assertCount(2, $this->archiveRequests, 'Redemandé plus tard');
    }

    public function testUnavailableServiceGivesNoWeather(): void
    {
        $down = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 503]));
        $weather = new OpenMeteoWeather($down, $down, new ArrayAdapter(), new MockClock('2026-11-15'), minIntervalSeconds: 0.0);

        self::assertNull($weather->during(48.39, -4.49, new \DateTimeImmutable('@'.self::DAY), 3600));
    }

    public function testStoredArrayRoundTrip(): void
    {
        $weather = new Weather(temperature: 12.3, code: 80, apparentTemperature: 10.1, humidity: 75, dewPoint: 8.0, windSpeed: 15.2, windGusts: 30.0, windDirection: 350, precipitation: 1.2);

        self::assertEquals($weather, Weather::fromArray($weather->toArray()));
        self::assertSame('Averses', $weather->conditionLabel());
        self::assertSame('rain', $weather->conditionIcon());
        self::assertSame('N', $weather->windDirectionLabel());
        self::assertNull(Weather::fromArray(['code' => 1]), 'Sans température, pas de météo');
    }

    private function weather(string $now, bool $missingHours = false): OpenMeteoWeather
    {
        $respond = static function (string $url) use ($missingHours): JsonMockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $first = strtotime(\is_string($query['start_date'] ?? null) ? $query['start_date'].' UTC' : 'now');
            $hours = range(0, 47);

            return new JsonMockResponse(['hourly' => [
                'time' => array_map(static fn (int $h): int => (int) $first + $h * 3600, $hours),
                'temperature_2m' => array_map(static fn (int $h): ?float => $missingHours && $h > 6 ? null : (float) $h, $hours),
                'apparent_temperature' => array_fill(0, 48, 5.0),
                'relative_humidity_2m' => array_fill(0, 48, 80),
                'dew_point_2m' => array_fill(0, 48, 4.0),
                'precipitation' => array_map(static fn (int $h): float => 0.1 * $h, $hours),
                'weather_code' => array_fill(0, 48, 2),
                'wind_speed_10m' => array_fill(0, 48, 20.0),
                'wind_gusts_10m' => array_fill(0, 48, 40.0),
                'wind_direction_10m' => array_fill(0, 48, 270),
            ]]);
        };

        $archive = new MockHttpClient(function (string $method, string $url) use ($respond): JsonMockResponse {
            $this->archiveRequests[] = $url;

            return $respond($url);
        }, 'https://archive-api.open-meteo.com');
        $forecast = new MockHttpClient(function (string $method, string $url) use ($respond): JsonMockResponse {
            $this->forecastRequests[] = $url;

            return $respond($url);
        }, 'https://api.open-meteo.com');

        return new OpenMeteoWeather($archive, $forecast, new ArrayAdapter(), new MockClock($now), minIntervalSeconds: 0.0);
    }
}
