<?php

declare(strict_types=1);

namespace App\Elevation;

use App\Http\NetworkGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Altitude du terrain partout dans le monde (modèle Copernicus GLO-90, maille de 90 m ; 0 en mer),
 * d'après l'API d'altitude d'Open-Meteo : complète l'IGN là où il n'a pas de donnée.
 *
 * Service public gratuit et sans clé (usage non commercial). Réponses gardées dans le dossier de
 * données, comme celles de l'IGN.
 *
 * @see https://open-meteo.com/en/docs/elevation-api
 */
final class OpenMeteoElevation
{
    /** Nombre maximal de points par requête accepté par le service. */
    private const POINTS_PER_REQUEST = 100;
    /** Temps réseau maximal par requête web (filet de sécurité). */
    private const WEB_BUDGET_SECONDS = 60.0;
    /**
     * Quota par minute du service gratuit, où une requête de 100 points compte pour plusieurs appels :
     * au-delà, réponse 429 (D+ du terrain à compléter au prochain import).
     */
    private const MIN_INTERVAL_SECONDS = 1.5;

    private readonly NetworkGuard $guard;

    public function __construct(
        #[Target('openmeteo.client')] private readonly HttpClientInterface $client,
        #[Autowire(service: 'app.elevation_cache')] private readonly CacheInterface $cache,
        ?float $budgetSeconds = \PHP_SAPI === 'cli' ? null : self::WEB_BUDGET_SECONDS,
        float $minIntervalSeconds = self::MIN_INTERVAL_SECONDS,
    ) {
        $this->guard = new NetworkGuard('Open-Meteo', $budgetSeconds, $minIntervalSeconds);
    }

    /**
     * @param list<array{0: float, 1: float}> $coordinates latitude, longitude
     *
     * @return list<float>|null altitude de chaque point, ou null si le service est indisponible
     */
    public function elevations(array $coordinates): ?array
    {
        $elevations = [];
        foreach (array_chunk($coordinates, self::POINTS_PER_REQUEST) as $chunk) {
            $lat = implode(',', array_map(static fn (array $c): string => \sprintf('%.5F', $c[0]), $chunk));
            $lon = implode(',', array_map(static fn (array $c): string => \sprintf('%.5F', $c[1]), $chunk));

            try {
                /** @var list<float> $values */
                $values = $this->cache->get('openmeteo_'.hash('xxh128', $lat.'/'.$lon), fn (): array => $this->fetch($lat, $lon, \count($chunk)));
            } catch (\Throwable) {
                // Erreur réseau ou budget épuisé : rien n'est mis en cache, on réessaiera au prochain import.
                return null;
            }
            array_push($elevations, ...$values);
        }

        return [] === $elevations ? null : $elevations;
    }

    /**
     * @return list<float>
     */
    private function fetch(string $lat, string $lon, int $count): array
    {
        $data = $this->guard->call(fn (): array => $this->client->request('GET', '/v1/elevation', [
            'query' => ['latitude' => $lat, 'longitude' => $lon],
        ])->toArray());

        $elevations = [];
        foreach (\is_array($data['elevation'] ?? null) ? $data['elevation'] : [] as $value) {
            if (!is_numeric($value)) {
                throw new \UnexpectedValueException('Altitude manquante dans la réponse d\'Open-Meteo.');
            }
            $elevations[] = (float) $value;
        }
        if (\count($elevations) !== $count) {
            throw new \UnexpectedValueException('Réponse incomplète d\'Open-Meteo.');
        }

        return $elevations;
    }
}
