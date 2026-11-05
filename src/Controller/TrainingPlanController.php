<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\TrainingWeekOverride;
use App\Form\PlanSettingsType;
use App\Prediction\FitnessAnalyzer;
use App\Repository\AthleteProfileRepository;
use App\Repository\TrainingWeekOverrideRepository;
use App\Training\PlanReviewer;
use App\Training\SessionType;
use App\Training\TrainingPlanBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TrainingPlanController extends AbstractController
{
    private const MAX_DESCRIPTION = 500;

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
            'session_types' => array_filter(SessionType::cases(), static fn (SessionType $type): bool => SessionType::Race !== $type),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/programme/semaine/{week}', name: 'plan_week', requirements: ['week' => '\d{4}-\d{2}-\d{2}'], methods: ['POST'])]
    public function adjustWeek(string $week, Request $request): Response
    {
        $monday = $this->monday($week, $request);
        $override = $this->overrides->findOneByWeek($monday) ?? new TrainingWeekOverride($monday);
        $payload = $request->getPayload();
        $action = $payload->getString('action');
        if ('swap' === $action) {
            $from = $payload->getInt('from');
            $to = $payload->getInt('to');
            if ($from === $to || !self::isUpcoming($monday, $from) || !self::isUpcoming($monday, $to)) {
                $this->addFlash('error', 'Seuls deux jours différents, à venir, peuvent être échangés.');
            } else {
                $override->swapDays($from, $to);
            }
        } elseif ('reset' === $action) {
            $override->reset();
        } else {
            $profile = $this->profiles->current();
            $general = $profile->planSessionsPerWeek ?? $this->builder->defaultSessionsPerWeek($this->analyzer->analyze());
            $sessions = TrainingPlanBuilder::clampSessions($payload->getInt('sessions', $general));
            $override->adjust($sessions === $general ? null : $sessions);
        }
        $this->overrides->store($override);

        // Échange : retour à la même adresse, que Turbo rafraîchit sur place (défilement conservé).
        return $this->redirect($this->generateUrl('plan').('swap' === $action ? '' : '#semaine-'.$week));
    }

    #[Route('/programme/semaine/{week}/jour/{day}', name: 'plan_session', requirements: ['week' => '\d{4}-\d{2}-\d{2}', 'day' => '[1-7]'], methods: ['POST'])]
    public function editSession(string $week, int $day, Request $request): Response
    {
        $monday = $this->monday($week, $request);
        $override = $this->overrides->findOneByWeek($monday) ?? new TrainingWeekOverride($monday);
        $payload = $request->getPayload();
        $type = SessionType::tryFrom($payload->getString('type'));
        $description = trim($payload->getString('description'));
        $km = (float) str_replace(',', '.', $payload->getString('km'));

        if (!self::isUpcoming($monday, $day)) {
            $this->addFlash('error', 'Seules les séances à venir peuvent être modifiées.');
        } elseif ('reset' === $payload->getString('action')) {
            $override->forgetEdit($day);
        } elseif (null === $type || SessionType::Race === $type || '' === $description || mb_strlen($description) > self::MAX_DESCRIPTION || $km < 0.5 || $km > 100) {
            $this->addFlash('error', \sprintf('Séance non modifiée : choisir un type, décrire le contenu (%d caractères au plus) et donner une distance entre 0,5 et 100 km.', self::MAX_DESCRIPTION));
        } else {
            $override->editSession($day, $type->value, $description, round($km * 10) / 10);
        }
        $this->overrides->store($override);

        return $this->redirectToRoute('plan');
    }

    private function monday(string $week, Request $request): \DateTimeImmutable
    {
        if (!$this->isCsrfTokenValid('plan_week_'.$week, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $monday = \DateTimeImmutable::createFromFormat('!Y-m-d', $week);
        if (false === $monday || '1' !== $monday->format('N')) {
            throw $this->createNotFoundException('Semaine inconnue.');
        }

        return $monday;
    }

    /** Jour (1-7) de la semaine, aujourd'hui ou plus tard. */
    private static function isUpcoming(\DateTimeImmutable $monday, int $day): bool
    {
        return $day >= 1 && $day <= 7 && $monday->modify(\sprintf('+%d days', $day - 1)) >= new \DateTimeImmutable('today');
    }
}
