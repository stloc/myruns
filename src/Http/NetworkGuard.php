<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Garde-fous communs aux services publics appelés à l'import (IGN, Open-Meteo, Nominatim) :
 *  - temps réseau maximal (requête web seulement : l'import web avance déjà par étapes) ;
 *  - plus d'appel après 3 échecs de suite : un service en panne ne fait pas attendre le délai
 *    réseau pour chaque trace ;
 *  - intervalle minimal entre deux appels, quand le service l'exige (Nominatim : 1 par seconde).
 */
final class NetworkGuard
{
    private const MAX_CONSECUTIVE_FAILURES = 3;

    private float $networkSeconds = 0.0;
    private int $consecutiveFailures = 0;
    private ?float $lastCallAt = null;

    public function __construct(
        private readonly string $service,
        private readonly ?float $budgetSeconds,
        private readonly float $minIntervalSeconds = 0.0,
    ) {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     *
     * @throws \RuntimeException budget épuisé ou service tenu pour injoignable (rien à mettre en cache)
     */
    public function call(\Closure $call): mixed
    {
        if (null !== $this->budgetSeconds && $this->networkSeconds >= $this->budgetSeconds) {
            throw new \RuntimeException('Budget réseau épuisé.');
        }
        if ($this->consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
            throw new \RuntimeException(\sprintf('Service %s injoignable.', $this->service));
        }
        if (null !== $this->lastCallAt && ($wait = $this->lastCallAt + $this->minIntervalSeconds - microtime(true)) > 0) {
            usleep((int) ($wait * 1_000_000));
        }

        $start = microtime(true);
        try {
            $result = $call();
        } catch (\Throwable $e) {
            ++$this->consecutiveFailures;

            throw $e;
        } finally {
            $this->lastCallAt = microtime(true);
            $this->networkSeconds += $this->lastCallAt - $start;
        }
        $this->consecutiveFailures = 0;

        return $result;
    }
}
