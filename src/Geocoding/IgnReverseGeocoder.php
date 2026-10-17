<?php

declare(strict_types=1);

namespace App\Geocoding;

use App\Http\NetworkGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Commune d'une position GPS d'après le géocodage inverse de l'IGN (parcelles cadastrales, France
 * seulement : la commune est connue jusqu'en montagne ; ailleurs, CityFinder demande à Nominatim).
 *
 * Service public gratuit et sans clé. Les réponses sont gardées dans le dossier de données, par
 * position arrondie à ~100 m : les sorties qui partent de la maison ne font qu'un appel.
 *
 * @see https://geoservices.ign.fr/documentation/services/services-geoplateforme/geocodage
 */
final class IgnReverseGeocoder
{
    /** Temps réseau maximal par requête web (filet de sécurité). */
    private const WEB_BUDGET_SECONDS = 30.0;

    private readonly NetworkGuard $guard;

    public function __construct(
        #[Target('ign.client')] private readonly HttpClientInterface $client,
        #[Autowire(service: 'app.place_cache')] private readonly CacheInterface $cache,
        ?float $budgetSeconds = \PHP_SAPI === 'cli' ? null : self::WEB_BUDGET_SECONDS,
    ) {
        $this->guard = new NetworkGuard('IGN', $budgetSeconds);
    }

    /**
     * @return string|null nom de la commune, ou null (hors de France, service indisponible,
     *                     budget réseau épuisé)
     */
    public function cityAt(float $latitude, float $longitude): ?string
    {
        $lat = \sprintf('%.3F', $latitude);
        $lon = \sprintf('%.3F', $longitude);

        try {
            /** @var array{city: string|null} $place */
            $place = $this->cache->get('city_'.$lat.'_'.$lon, fn (): array => ['city' => $this->fetch($lat, $lon)]);
        } catch (\Throwable) {
            // Erreur réseau ou budget épuisé : rien n'est mis en cache, on réessaiera au prochain import.
            return null;
        }

        return $place['city'];
    }

    /**
     * @return string|null null (mis en cache) hors de la zone couverte
     */
    private function fetch(string $lat, string $lon): ?string
    {
        $data = $this->guard->call(fn (): array => $this->client->request('GET', '/geocodage/reverse', [
            'query' => ['lat' => $lat, 'lon' => $lon, 'index' => 'parcel', 'limit' => 1],
        ])->toArray());

        $features = \is_array($data['features'] ?? null) ? $data['features'] : [];
        $first = $features[0] ?? null;
        $city = \is_array($first) && \is_array($first['properties'] ?? null) ? ($first['properties']['city'] ?? null) : null;

        return \is_string($city) && '' !== trim($city) ? trim($city) : null;
    }
}
