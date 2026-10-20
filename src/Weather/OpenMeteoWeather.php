<?php

declare(strict_types=1);

namespace App\Weather;

use App\Http\NetworkGuard;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Météo heure par heure à l'endroit et au moment d'une sortie, partout dans le monde, d'après
 * Open-Meteo : historique (réanalyse ERA5, maille d'environ 10 km, depuis 1940) et, pour les
 * derniers jours que l'historique n'a pas encore, l'API de prévision (qui garde les jours passés).
 *
 * Service public gratuit et sans clé (usage non commercial). Réponses gardées dans le dossier de
 * données, par jour et par position arrondie à ~1 km.
 *
 * @see https://open-meteo.com/en/docs/historical-weather-api
 */
final class OpenMeteoWeather
{
    /** Temps réseau maximal par requête web (filet de sécurité). */
    private const WEB_BUDGET_SECONDS = 30.0;
    /** Quota par minute du service gratuit, partagé avec l'altitude. */
    private const MIN_INTERVAL_SECONDS = 1.0;
    /** Retard de l'historique : en deçà, on interroge l'API de prévision. */
    private const ARCHIVE_DELAY_DAYS = 7;
    private const HOURLY = ['temperature_2m', 'apparent_temperature', 'relative_humidity_2m', 'dew_point_2m', 'precipitation', 'weather_code', 'wind_speed_10m', 'wind_gusts_10m', 'wind_direction_10m'];

    private readonly NetworkGuard $guard;

    public function __construct(
        #[Target('openmeteo_archive.client')] private readonly HttpClientInterface $archive,
        #[Target('openmeteo.client')] private readonly HttpClientInterface $forecast,
        #[Autowire(service: 'app.weather_cache')] private readonly CacheInterface $cache,
        private readonly ClockInterface $clock = new NativeClock(),
        ?float $budgetSeconds = \PHP_SAPI === 'cli' ? null : self::WEB_BUDGET_SECONDS,
        float $minIntervalSeconds = self::MIN_INTERVAL_SECONDS,
    ) {
        $this->guard = new NetworkGuard('Open-Meteo', $budgetSeconds, $minIntervalSeconds);
    }

    /**
     * @return Weather|null null si le service est indisponible ou n'a pas encore cette heure
     */
    public function during(float $latitude, float $longitude, \DateTimeImmutable $start, int $durationSeconds): ?Weather
    {
        $utc = new \DateTimeZone('UTC');
        $start = $start->setTimezone($utc);
        $end = $start->modify(\sprintf('+%d seconds', max(0, $durationSeconds)));
        $lat = \sprintf('%.2F', $latitude);
        $lon = \sprintf('%.2F', $longitude);
        $firstDay = $start->format('Y-m-d');
        $lastDay = $end->format('Y-m-d');

        try {
            /** @var array<string, list<float|int|null>> $hourly */
            $hourly = $this->cache->get('weather_'.$lat.'_'.$lon.'_'.$firstDay.'_'.$lastDay, fn (): array => $this->fetch($lat, $lon, $firstDay, $lastDay, $end));
        } catch (\Throwable) {
            // Erreur réseau, budget épuisé ou heure pas encore publiée : rien n'est mis en cache, on réessaiera.
            return null;
        }

        return self::summarize($hourly, $start->getTimestamp(), $end->getTimestamp());
    }

    /**
     * @param array<string, list<float|int|null>> $hourly séries horaires, « time » en secondes Unix (UTC)
     */
    private static function summarize(array $hourly, int $start, int $end): ?Weather
    {
        $times = $hourly['time'] ?? [];
        $middle = intdiv($start + $end, 2);
        $nearest = null;
        $precipitation = 0.0;
        foreach ($times as $i => $time) {
            if (null === $nearest || abs($time - $middle) < abs($times[$nearest] - $middle)) {
                $nearest = $i;
            }
            // Cumul de l'heure écoulée avant $time : compté si cette heure recoupe la sortie.
            if ($time > $start && $time - 3600 < $end) {
                $precipitation += (float) ($hourly['precipitation'][$i] ?? 0.0);
            }
        }
        if (null === $nearest) {
            return null;
        }

        $value = static fn (string $key): float|int|null => $hourly[$key][$nearest] ?? null;

        return Weather::fromArray([
            'temperature' => $value('temperature_2m'),
            'code' => $value('weather_code'),
            'apparent_temperature' => $value('apparent_temperature'),
            'humidity' => $value('relative_humidity_2m'),
            'dew_point' => $value('dew_point_2m'),
            'wind_speed' => $value('wind_speed_10m'),
            'wind_gusts' => $value('wind_gusts_10m'),
            'wind_direction' => $value('wind_direction_10m'),
            'precipitation' => round($precipitation, 1),
        ]);
    }

    /**
     * @return array<string, list<float|int|null>>
     */
    private function fetch(string $lat, string $lon, string $firstDay, string $lastDay, \DateTimeImmutable $end): array
    {
        $recent = $end > $this->clock->now()->modify(\sprintf('-%d days', self::ARCHIVE_DELAY_DAYS));
        [$client, $endpoint] = $recent ? [$this->forecast, '/v1/forecast'] : [$this->archive, '/v1/archive'];

        $data = $this->guard->call(fn (): array => $client->request('GET', $endpoint, [
            'query' => [
                'latitude' => $lat,
                'longitude' => $lon,
                'start_date' => $firstDay,
                'end_date' => $lastDay,
                'hourly' => implode(',', self::HOURLY),
                'timezone' => 'GMT',
                'timeformat' => 'unixtime',
            ],
        ])->toArray());

        $hourly = \is_array($data['hourly'] ?? null) ? $data['hourly'] : [];
        $series = [];
        foreach (['time', ...self::HOURLY] as $key) {
            $values = \is_array($hourly[$key] ?? null) ? $hourly[$key] : [];
            $series[$key] = array_values(array_map(static fn (mixed $v): float|int|null => \is_int($v) || \is_float($v) ? $v : null, $values));
        }
        // Heures pas encore publiées (null) : ne rien mettre en cache, la donnée viendra plus tard.
        if ([] === $series['time'] || \in_array(null, $series['temperature_2m'], true) || \in_array(null, $series['weather_code'], true)) {
            throw new \UnexpectedValueException('Météo incomplète dans la réponse d\'Open-Meteo.');
        }
        $series['time'] = array_map(static fn (float|int|null $t): int => (int) $t, $series['time']);

        return $series;
    }
}
