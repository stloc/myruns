<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\AiCoach;
use App\Form\GoalType;
use App\Form\Model\GoalRequest;
use App\Prediction\GoalPredictor;
use App\Repository\AthleteProfileRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GoalController extends AbstractController
{
    #[Route('/objectif', name: 'goal', methods: ['GET', 'POST'])]
    public function index(Request $request, GoalPredictor $predictor, AiCoach $coach, AthleteProfileRepository $profiles, LoggerInterface $logger): Response
    {
        $profile = $profiles->current();
        $goalRequest = GoalRequest::fromProfile($profile);
        $form = $this->createForm(GoalType::class, $goalRequest);
        $form->handleRequest($request);

        $prediction = null;
        $analysis = null;
        $aiError = null;

        if ($form->isSubmitted() && $form->isValid() && null !== $goalRequest->date) {
            $profile->rememberGoal(trim($goalRequest->distance), trim($goalRequest->time), $goalRequest->date);
            $profiles->save($profile);

            $prediction = $predictor->predict($goalRequest->toGoal());

            // Le bouton « Calculer + analyse IA » envoie action=ai (bouton du template).
            if (null !== $prediction && 'ai' === $request->request->getString('action')) {
                try {
                    $analysis = $coach->analyze($prediction);
                } catch (\Throwable $e) {
                    $logger->error('Analyse IA en échec : {message}', ['message' => $e->getMessage(), 'exception' => $e]);
                    $aiError = $e->getMessage();
                }
            }
        }

        return $this->render('goal/index.html.twig', [
            'form' => $form,
            'submitted' => $form->isSubmitted() && $form->isValid(),
            'prediction' => $prediction,
            'analysis' => $analysis,
            'ai_error' => $aiError,
        ], new Response(status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
