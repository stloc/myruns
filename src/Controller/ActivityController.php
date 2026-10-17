<?php

declare(strict_types=1);

namespace App\Controller;

use App\Chart\SplitTable;
use App\Entity\Activity;
use App\Enum\SplitKind;
use App\Enum\Sport;
use App\Repository\ActivityRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class ActivityController extends AbstractController
{
    private const PER_PAGE = 50;

    #[Route('/activites', name: 'activity_index', methods: ['GET'])]
    public function index(
        ActivityRepository $activities,
        #[MapQueryParameter] ?string $sport = null,
        #[MapQueryParameter(options: ['min_range' => 1])] int $page = 1,
    ): Response {
        $selectedSport = null !== $sport ? Sport::tryFrom($sport) : null;
        $total = $activities->countBySport($selectedSport);

        return $this->render('activity/index.html.twig', [
            'activities' => $activities->findLatest(self::PER_PAGE, ($page - 1) * self::PER_PAGE, $selectedSport),
            'sports' => Sport::cases(),
            'selected_sport' => $selectedSport,
            'page' => $page,
            'page_count' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
        ]);
    }

    #[Route('/activites/{id}', name: 'activity_show', methods: ['GET'])]
    public function show(Activity $activity): Response
    {
        $laps = $activity->splitsOf(SplitKind::Lap);

        return $this->render('activity/show.html.twig', [
            'activity' => $activity,
            'kilometers' => SplitTable::fromSplits($activity->splitsOf(SplitKind::Kilometer)),
            // Tours affichés seulement s'ils apportent autre chose que le découpage au km.
            'laps' => [] !== $laps && !SplitTable::areAutomaticKilometers($laps) ? SplitTable::fromSplits($laps) : null,
            'automatic_laps' => [] !== $laps && SplitTable::areAutomaticKilometers($laps),
        ]);
    }
}
