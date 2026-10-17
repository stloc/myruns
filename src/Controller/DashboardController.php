<?php

declare(strict_types=1);

namespace App\Controller;

use App\Chart\ColumnChart;
use App\Prediction\FitnessAnalyzer;
use App\Repository\ActivityRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    private const CHART_WEEKS = 26;

    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function index(ActivityRepository $activities, FitnessAnalyzer $analyzer): Response
    {
        $weekly = $activities->weeklyRunningKm(self::CHART_WEEKS);

        return $this->render('dashboard/index.html.twig', [
            'fitness' => $analyzer->analyze(),
            'activity_count' => $activities->count(),
            'latest_activities' => $activities->findLatest(8),
            'weekly_km' => $weekly,
            'chart' => ColumnChart::fromSeries($weekly),
        ]);
    }
}
