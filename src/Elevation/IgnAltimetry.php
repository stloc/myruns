<?php

declare(strict_types=1);

namespace App\Elevation;

use App\Http\NetworkGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Altitude du terrain d'après le service d'altimétrie de l'IGN (RGE ALTI, France seulement :
 * ailleurs, ElevationCorrector complète avec OpenMeteoElevation).
 *
 * Service public gratuit et sans clé. Les réponses sont gardées dans le dossier de données :
 * un réimport de la même trace ne refait aucun appel réseau.
 *
 * @see https://geoservices.ign.fr/documentation/services/services-geoplateforme/altimetrie
 */
final class IgnAltimetry
{
    /** Nombre maximal de points par requête accepté par le service. */
    public const MAX_POINTS = 5000;
    /** Valeur renvoyée hors de la zone couverte (étranger, mer). */
    private const NO_DATA = -99999.0;
    /** Temps réseau maximal par requête web (filet de sécurité). */
    private const WEB_BUDGET_SECONDS = 120.0;

    private readonly NetworkGuard $guard;

    public function __construct(
        #[Target('ign.client')] private readonly HttpClientInterface $client,
        #[Autowire(service: 'app.elevation_cache')] private readonly CacheInterface $cache,
        ?float $budgetSeconds = \PHP_SAPI === 'cli' ? null : self::WEB_BUDGET_SECONDS,
    ) {
        $this->guard = new NetworkGuard('IGN', $budgetSeconds);
    }

    /**
     * @param list<array{0: float, 1: float}> $coordinates latitude, longitude (au plus MAX_POINTS)
     *
     * @return list<float|null>|null altitude de chaque point (null hors de la zone couverte :
     *                               étranger, mer), ou null si le service est indisponible
     */
    public function elevations(array $coordinates): ?array
    {
        if ([] === $coordinates || \count($coordinates) > self::MAX_POINTS) {
            return null;
        }

        $lat = implode('|', array_map(static fn (array $c): string => \sprintf('%.6F', $c[0]), $coordinates));
        $lon = implode('|', array_map(static fn (array $c): string => \sprintf('%.6F', $c[1]), $coordinates));

        try {
            /** @var list<float|null>|null $elevations */
            $elevations = $this->cache->get('ign_'.hash('xxh128', $lat.'/'.$lon), fn (): array => $this->fetch($lat, $lon, \count($coordinates)));
        } catch (\Throwable) {
            // Erreur réseau ou budget épuisé : rien n'est mis en cache, on réessaiera au prochain import.
            return null;
        }

        // Ancien format du cache : null dès qu'un point était hors zone, sans dire lequel.
        return $elevations ?? array_fill(0, \count($coordinates), null);
    }

    /**
     * @return list<float|null>
     */
    private function fetch(string $lat, string $lon, int $count): array
    {
        $data = $this->guard->call(fn (): array => $this->client->request('POST', '/altimetrie/1.0/calcul/alti/rest/elevation.json', [
            'json' => ['lon' => $lon, 'lat' => $lat, 'resource' => 'ign_rge_alti_wld', 'zonly' => 'true'],
        ])->toArray());

        $elevations = [];
        foreach (\is_array($data['elevations'] ?? null) ? $data['elevations'] : [] as $value) {
            $elevations[] = is_numeric($value) && (float) $value > self::NO_DATA ? (float) $value : null;
        }
        if (\count($elevations) !== $count) {
            throw new \UnexpectedValueException('Réponse incomplète du service d\'altimétrie.');
        }

        return $elevations;
    }
}
