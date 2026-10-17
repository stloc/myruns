<?php

declare(strict_types=1);

namespace App\Controller;

use App\Chart\RouteMap;
use App\Chart\SplitTable;
use App\Chart\StartMap;
use App\Entity\Activity;
use App\Enum\ActivitySort;
use App\Enum\SplitKind;
use App\Enum\Sport;
use App\Repository\ActivityRepository;
use App\Repository\ActivitySearch;
use App\Route\SameRouteFinder;
use App\Training\PlanReviewer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class ActivityController extends AbstractController
{
    private const PER_PAGE = 50;

    #[Route('/activites', name: 'activity_index', methods: ['GET'])]
    public function index(
        Request $request,
        ActivityRepository $activities,
        #[MapQueryParameter(options: ['min_range' => 1])] int $page = 1,
    ): Response {
        $search = ActivitySearch::fromQuery($request->query->all());
        $total = $activities->countMatching($search);
        $results = $activities->search($search, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return $this->render('activity/index.html.twig', [
            'activities' => $results,
            'with_splits' => $activities->idsWithSplits($results),
            'search' => $search,
            'sports' => Sport::cases(),
            'sorts' => ActivitySort::cases(),
            'all_runs' => ActivitySearch::ALL_RUNS,
            'page' => $page,
            'page_count' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
            'start_map' => StartMap::fromPositions(
                $activities->startPositions($search),
                fn (string $id): string => $this->generateUrl('activity_show', ['id' => $id]),
            ),
        ]);
    }

    #[Route('/activites/{id}', name: 'activity_show', methods: ['GET'])]
    public function show(Activity $activity, PlanReviewer $reviewer, SameRouteFinder $sameRoute): Response
    {
        $laps = $activity->splitsOf(SplitKind::Lap);

        return $this->render('activity/show.html.twig', [
            'activity' => $activity,
            'route_map' => RouteMap::fromPoints($activity->routePoints(), $activity->sport->isRunning()),
            'kilometers' => SplitTable::fromSplits($activity->splitsOf(SplitKind::Kilometer)),
            // Tours affichés seulement s'ils apportent autre chose que le découpage au km.
            'laps' => [] !== $laps && !SplitTable::areAutomaticKilometers($laps) ? SplitTable::fromSplits($laps) : null,
            'automatic_laps' => [] !== $laps && SplitTable::areAutomaticKilometers($laps),
            'same_route' => $sameRoute->compare($activity),
            // Séance du programme (semaine en cours ou précédente) à laquelle la sortie est rattachée.
            'session_review' => $activity->sport->isRunning() ? $reviewer->review()?->sessionFor($activity) : null,
        ]);
    }
}
