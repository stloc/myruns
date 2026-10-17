<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\TrainingWeekOverride;
use App\Form\PlanSettingsType;
use App\Prediction\FitnessAnalyzer;
use App\Repository\AthleteProfileRepository;
use App\Repository\TrainingWeekOverrideRepository;
use App\Training\PlanReviewer;
use App\Training\TrainingPlanBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TrainingPlanController extends AbstractController
{
    public function __construct(
        private readonly AthleteProfileRepository $profiles,
        private readonly TrainingWeekOverrideRepository $overrides,
        private readonly FitnessAnalyzer $analyzer,
        private readonly TrainingPlanBuilder $builder,
        private readonly PlanReviewer $reviewer,
    ) {
    }

    #[Route('/programme', name: 'plan', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $profile = $this->profiles->current();
        $fitness = $this->analyzer->analyze();
        $form = $this->createForm(PlanSettingsType::class, $profile, ['default_sessions' => $this->builder->defaultSessionsPerWeek($fitness)]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->profiles->save($profile);
            $this->addFlash('success', 'Réglages du programme enregistrés.');

            return $this->redirectToRoute('plan');
        }

        $review = null === $profile->lastGoal() ? null : $this->reviewer->review();

        return $this->render('plan/index.html.twig', [
            'goal' => $profile->lastGoal(),
            'plan' => $review?->plan,
            'review' => $review,
            'settings_form' => $form,
            'session_choices' => range(TrainingPlanBuilder::MIN_SESSIONS, TrainingPlanBuilder::MAX_SESSIONS),
            'today' => new \DateTimeImmutable('today'),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/programme/semaine/{week}', name: 'plan_week', requirements: ['week' => '\d{4}-\d{2}-\d{2}'], methods: ['POST'])]
    public function adjustWeek(string $week, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('plan_week_'.$week, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $monday = \DateTimeImmutable::createFromFormat('!Y-m-d', $week);
        if (false === $monday || '1' !== $monday->format('N')) {
            throw $this->createNotFoundException('Semaine inconnue.');
        }

        $override = $this->overrides->findOneByWeek($monday) ?? new TrainingWeekOverride($monday);
        $payload = $request->getPayload();
        if ('reset' === $payload->getString('action')) {
            $override->adjust(null, null);
        } else {
            $profile = $this->profiles->current();
            $general = $profile->planSessionsPerWeek ?? $this->builder->defaultSessionsPerWeek($this->analyzer->analyze());
            $sessions = TrainingPlanBuilder::clampSessions($payload->getInt('sessions', $general));
            // Case décochée : non envoyée ; « club_present » distingue « décochée » de « pas de club ».
            $club = $payload->has('club_present') && !$payload->has('club') ? false : null;
            $override->adjust($club, $sessions === $general ? null : $sessions);
        }
        $this->overrides->store($override);

        return $this->redirect($this->generateUrl('plan').'#semaine-'.$week);
    }
}
