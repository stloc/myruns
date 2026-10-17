<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\ImportType;
use App\Form\Model\ImportRequest;
use App\Import\ImportJob;
use App\Import\ImportJobs;
use App\Import\ImportResult;
use App\Sync\IntervalsSynchronizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

final class ImportController extends AbstractController
{
    public function __construct(
        private readonly ImportJobs $jobs,
        private readonly IntervalsSynchronizer $synchronizer,
        #[Autowire('%env(STRAVA_EXPORT_PATH)%')] private readonly string $defaultPath,
        #[Autowire('%app.data_dir%/uploads')] private readonly string $uploadDir,
        #[Autowire('%app.import_step_seconds%')] private readonly float $stepSeconds,
    ) {
    }

    #[Route('/import', name: 'import', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $importRequest = new ImportRequest();
        $form = $this->createForm(ImportType::class, $importRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== $importRequest->file) {
            // Nom de fichier généré : seule l'extension (validée) vient de l'utilisateur.
            $id = ImportJobs::newId();
            $file = $importRequest->file->move($this->uploadDir, $id.'.'.$importRequest->extension());

            return $this->advance($this->jobs->create($file->getPathname(), true, $id), $request);
        }

        return $this->render('import/index.html.twig', [
            'form' => $form,
            'default_path' => $this->defaultPath,
            'intervals_configured' => $this->synchronizer->isConfigured(),
            'pending_jobs' => $this->jobs->pending(),
        ], new Response(status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/import/dossier', name: 'import_folder', methods: ['POST'])]
    #[IsCsrfTokenValid('import_folder')]
    public function importFolder(Request $request): Response
    {
        if ('' === $this->defaultPath) {
            $this->addFlash('error', 'Aucun dossier configuré : renseignez STRAVA_EXPORT_PATH dans .env.local.');

            return $this->redirectToRoute('import');
        }

        return $this->advance($this->jobs->create($this->defaultPath, false), $request);
    }

    /**
     * Avancement d'un import trop long pour une seule requête : la page enchaîne les étapes
     * (assets/app.js), le bouton « Continuer » sert sans JavaScript ou après une erreur.
     */
    #[Route('/import/{id}', name: 'import_job', requirements: ['id' => '[0-9a-f]{16}'], methods: ['GET'])]
    public function job(string $id): Response
    {
        $job = $this->jobs->find($id);
        if (null === $job) {
            return $this->redirectToRoute('import');
        }

        return $this->render('import/job.html.twig', ['job' => $job]);
    }

    #[Route('/import/{id}/suite', name: 'import_job_continue', requirements: ['id' => '[0-9a-f]{16}'], methods: ['POST'])]
    #[IsCsrfTokenValid('import_job')]
    public function continueJob(string $id, Request $request): Response
    {
        $job = $this->jobs->find($id);
        if (null === $job) {
            $this->addFlash('warning', 'Cet import est déjà terminé ou a été abandonné.');

            return $this->finished($request);
        }

        return $this->advance($job, $request);
    }

    #[Route('/import/{id}/abandon', name: 'import_job_abandon', requirements: ['id' => '[0-9a-f]{16}'], methods: ['POST'])]
    #[IsCsrfTokenValid('import_job')]
    public function abandonJob(string $id): Response
    {
        $job = $this->jobs->find($id);
        if (null !== $job) {
            $this->jobs->delete($job);
            $this->addFlash('warning', \sprintf('Import interrompu (%d trace(s) lue(s) sur %d) : les activités déjà importées sont conservées, relancez l\'import pour compléter le reste.', $job->done, $job->total ?? 0));
        }

        return $this->redirectToRoute('import');
    }

    #[Route('/import/intervals', name: 'import_intervals', methods: ['POST'])]
    #[IsCsrfTokenValid('import_intervals')]
    public function syncIntervals(): Response
    {
        if (!$this->synchronizer->isConfigured()) {
            $this->addFlash('error', 'Synchro non configurée : enregistrez votre clé intervals.icu dans les Réglages.');

            return $this->redirectToRoute('import');
        }

        try {
            $result = $this->synchronizer->sync();
            $this->addFlash('success', $result->summary().'.');
            foreach (\array_slice($result->import->errors, 0, 5) as $error) {
                $this->addFlash('warning', $error);
            }
        } catch (HttpExceptionInterface $e) {
            $this->addFlash('error', 'intervals.icu ne répond pas ou refuse la clé : '.$e->getMessage());
        }

        return $this->redirectToRoute('import');
    }

    /**
     * Une étape d'import ; réponse JSON pour la page d'avancement, redirection sinon.
     */
    private function advance(ImportJob $job, Request $request): Response
    {
        // Libère le verrou de session pendant l'étape : sinon toute autre page (quitter, « Arrêter »)
        // attendrait la fin de l'étape. Le message final rouvre la session.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        try {
            $this->jobs->step($job, $this->stepSeconds);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->finished($request);
        }

        if ($job->isFinished()) {
            $this->flashResult($job->result);

            return $this->finished($request);
        }

        if (self::wantsJson($request)) {
            return new JsonResponse(['done' => $job->done, 'total' => $job->total, 'percent' => $job->percent()]);
        }

        return $this->redirectToRoute('import_job', ['id' => $job->id]);
    }

    private function finished(Request $request): Response
    {
        return self::wantsJson($request)
            ? new JsonResponse(['redirect' => $this->generateUrl('import')])
            : $this->redirectToRoute('import');
    }

    private static function wantsJson(Request $request): bool
    {
        return \in_array('application/json', $request->getAcceptableContentTypes(), true);
    }

    private function flashResult(ImportResult $result): void
    {
        $this->addFlash('success', 'Import terminé : '.$result->summary().'.');
        foreach (\array_slice($result->errors, 0, 5) as $error) {
            $this->addFlash('warning', $error);
        }
    }
}
