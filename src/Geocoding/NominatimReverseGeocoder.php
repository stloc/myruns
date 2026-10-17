<?php

declare(strict_types=1);

namespace App\Geocoding;

use App\Http\NetworkGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Ville d'une position GPS partout dans le monde, d'après le géocodage inverse de Nominatim
 * (OpenStreetMap) : complète l'IGN hors de France.
 *
 * Service public gratuit et sans clé. Sa charte impose au plus un appel par seconde, un
 * User-Agent identifiant l'application (client `nominatim.client`) et la mise en cache des
 * réponses : gardées dans le dossier de données, par position arrondie à ~100 m.
 *
 * @see https://operations.osmfoundation.org/policies/nominatim/
 */
final class NominatimReverseGeocoder
{
    /** Temps réseau maximal par requête web (filet de sécurité). */
    private const WEB_BUDGET_SECONDS = 30.0;
    /** Niveau de détail « ville » de Nominatim. */
    private const CITY_ZOOM = 10;
    /** Clés de l'adresse, de la plus précise à la plus large. */
    private const PLACE_KEYS = ['city', 'town', 'village', 'municipality'];

    private readonly NetworkGuard $guard;

    public function __construct(
        #[Target('nominatim.client')] private readonly HttpClientInterface $client,
        #[Autowire(service: 'app.place_cache')] private readonly CacheInterface $cache,
        ?float $budgetSeconds = \PHP_SAPI === 'cli' ? null : self::WEB_BUDGET_SECONDS,
        float $minIntervalSeconds = 1.0,
    ) {
        $this->guard = new NetworkGuard('Nominatim', $budgetSeconds, $minIntervalSeconds);
    }

    /**
     * @return string|null nom de la ville, ou null (en mer, service indisponible, budget épuisé)
     */
    public function cityAt(float $latitude, float $longitude): ?string
    {
        $lat = \sprintf('%.3F', $latitude);
        $lon = \sprintf('%.3F', $longitude);

        try {
            /** @var array{city: string|null} $place */
            $place = $this->cache->get('osm_city_'.$lat.'_'.$lon, fn (): array => ['city' => $this->fetch($lat, $lon)]);
        } catch (\Throwable) {
            // Erreur réseau ou budget épuisé : rien n'est mis en cache, on réessaiera au prochain import.
            return null;
        }

        return $place['city'];
    }

    /**
     * @return string|null null (mis en cache) si aucune ville n'est connue à cet endroit
     */
    private function fetch(string $lat, string $lon): ?string
    {
        $data = $this->guard->call(fn (): array => $this->client->request('GET', '/reverse', [
            'query' => ['format' => 'jsonv2', 'lat' => $lat, 'lon' => $lon, 'zoom' => self::CITY_ZOOM, 'accept-language' => 'fr'],
        ])->toArray());

        $address = \is_array($data['address'] ?? null) ? $data['address'] : [];
        foreach (self::PLACE_KEYS as $key) {
            $city = $address[$key] ?? null;
            if (\is_string($city) && '' !== trim($city)) {
                return trim($city);
            }
        }

        return null;
    }
}
