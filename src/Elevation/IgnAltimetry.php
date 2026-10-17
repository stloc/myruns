<?php

declare(strict_types=1);

namespace App\Elevation;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Altitude du terrain d'après le service d'altimétrie de l'IGN (RGE ALTI, France seulement).
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
    /**
     * Temps réseau maximal par requête web : le premier import d'une archive complète dépasserait
     * le délai d'exécution de PHP. Les traces restantes sont corrigées à l'import suivant.
     */
    private const WEB_BUDGET_SECONDS = 120.0;

    private float $networkSeconds = 0.0;

    public function __construct(
        #[Target('ign.client')] private readonly HttpClientInterface $client,
        #[Autowire(service: 'app.elevation_cache')] private readonly CacheInterface $cache,
        private readonly ?float $budgetSeconds = \PHP_SAPI === 'cli' ? null : self::WEB_BUDGET_SECONDS,
    ) {
    }

    /**
     * @param list<array{0: float, 1: float}> $coordinates latitude, longitude (au plus MAX_POINTS)
     *
     * @return list<float>|null altitude de chaque point, ou null si une altitude manque
     *                          (hors de France, service indisponible, budget réseau épuisé)
     */
    public function elevations(array $coordinates): ?array
    {
        if ([] === $coordinates || \count($coordinates) > self::MAX_POINTS) {
            return null;
        }

        $lat = implode('|', array_map(static fn (array $c): string => \sprintf('%.6F', $c[0]), $coordinates));
        $lon = implode('|', array_map(static fn (array $c): string => \sprintf('%.6F', $c[1]), $coordinates));

        try {
            /** @var list<float>|null $elevations */
            $elevations = $this->cache->get('ign_'.hash('xxh128', $lat.'/'.$lon), fn (): ?array => $this->fetch($lat, $lon, \count($coordinates)));
        } catch (\Throwable) {
            // Erreur réseau ou budget épuisé : rien n'est mis en cache, on réessaiera au prochain import.
            return null;
        }

        return $elevations;
    }

    /**
     * @return list<float>|null null (mis en cache) si un point est hors de la zone couverte
     */
    private function fetch(string $lat, string $lon, int $count): ?array
    {
        if (null !== $this->budgetSeconds && $this->networkSeconds >= $this->budgetSeconds) {
            throw new \RuntimeException('Budget réseau épuisé.');
        }

        $start = microtime(true);
        try {
            $data = $this->client->request('POST', '/altimetrie/1.0/calcul/alti/rest/elevation.json', [
                'json' => ['lon' => $lon, 'lat' => $lat, 'resource' => 'ign_rge_alti_wld', 'zonly' => 'true'],
            ])->toArray();
        } finally {
            $this->networkSeconds += microtime(true) - $start;
        }

        $elevations = [];
        foreach (\is_array($data['elevations'] ?? null) ? $data['elevations'] : [] as $value) {
            if (!is_numeric($value) || (float) $value <= self::NO_DATA) {
                return null;
            }
            $elevations[] = (float) $value;
        }
        if (\count($elevations) !== $count) {
            throw new \UnexpectedValueException('Réponse incomplète du service d\'altimétrie.');
        }

        return $elevations;
    }
}
