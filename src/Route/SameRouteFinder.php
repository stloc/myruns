<?php

declare(strict_types=1);

namespace App\Route;

use App\Entity\Activity;
use App\Repository\ActivityRepository;

/**
 * Autres sorties sur le même parcours qu'une activité, pour comparer les temps.
 */
final readonly class SameRouteFinder
{
    /** Départs plus éloignés : pas le même parcours (évite de comparer toutes les traces). */
    private const START_RADIUS_METERS = 300.0;
    /** Écart de distance mesurée toléré entre deux montres sur le même parcours. */
    private const DISTANCE_RATIO = 0.1;

    public function __construct(
        private ActivityRepository $activities,
        private RouteMatcher $matcher,
    ) {
    }

    /**
     * Comparaison avec les autres sorties du parcours, ou null s'il n'y en a pas (ou pas de trace GPS).
     */
    public function compare(Activity $activity): ?RouteComparison
    {
        $route = $activity->routePoints();
        if ([] === $route) {
            return null;
        }

        $outings = [];
        foreach ($this->activities->findRouteCandidates($activity, self::START_RADIUS_METERS, self::DISTANCE_RATIO) as $candidate) {
            if ($candidate === $activity || $this->matcher->sameRoute($route, $candidate->routePoints())) {
                $outings[] = $candidate;
            }
        }

        return RouteComparison::of($activity, $outings);
    }
}
