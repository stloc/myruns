<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\RaceResult;
use App\Form\Model\RaceRequest;
use App\Form\ProfileType;
use App\Form\RaceType;
use App\Prediction\RunningExperience;
use App\Repository\AthleteProfileRepository;
use App\Repository\RaceResultRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly AthleteProfileRepository $profiles,
        private readonly RaceResultRepository $races,
        private readonly RunningExperience $experience,
    ) {
    }

    #[Route('/profil', name: 'profile', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $profile = $this->profiles->current();
        $profileForm = $this->createForm(ProfileType::class, $profile, ['inferred_running_since' => $this->experience->inferredSinceYear()]);
        $profileForm->handleRequest($request);
        if ($profileForm->isSubmitted() && $profileForm->isValid()) {
            $this->profiles->save($profile);
            $this->addFlash('success', 'Profil enregistré : les prochaines prédictions en tiennent compte.');

            return $this->redirectToRoute('profile');
        }

        $raceRequest = new RaceRequest();
        $raceForm = $this->createForm(RaceType::class, $raceRequest);
        $raceForm->handleRequest($request);
        if ($raceForm->isSubmitted() && $raceForm->isValid()) {
            $this->races->save($raceRequest->toRaceResult());
            $this->addFlash('success', 'Course ajoutée.');

            return $this->redirectToRoute('profile');
        }

        $invalid = ($profileForm->isSubmitted() && !$profileForm->isValid()) || ($raceForm->isSubmitted() && !$raceForm->isValid());

        return $this->render('profile/index.html.twig', [
            'profile_form' => $profileForm,
            'race_form' => $raceForm,
            'races' => $this->races->findLatestFirst(),
        ], new Response(status: $invalid ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/profil/course/{id}/supprimer', name: 'race_delete', methods: ['POST'])]
    public function deleteRace(RaceResult $race, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('race_delete_'.$race->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $this->races->delete($race);
        $this->addFlash('success', 'Course supprimée.');

        return $this->redirectToRoute('profile');
    }
}
