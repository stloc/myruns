<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\ImportType;
use App\Form\Model\ImportRequest;
use App\Import\ActivityImporter;
use App\Import\ImportResult;
use App\Sync\IntervalsSynchronizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

final class ImportController extends AbstractController
{
    public function __construct(
        private readonly ActivityImporter $importer,
        private readonly IntervalsSynchronizer $synchronizer,
        #[Autowire('%env(STRAVA_EXPORT_PATH)%')] private readonly string $defaultPath,
        #[Autowire('%app.data_dir%/uploads')] private readonly string $uploadDir,
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
            $filename = bin2hex(random_bytes(8)).'.'.$importRequest->extension();
            $file = $importRequest->file->move($this->uploadDir, $filename);

            try {
                $this->flashResult($this->importer->import($file->getPathname()));
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $this->addFlash('error', $e->getMessage());
            } finally {
                new Filesystem()->remove($file->getPathname());
            }

            return $this->redirectToRoute('import');
        }

        return $this->render('import/index.html.twig', [
            'form' => $form,
            'default_path' => $this->defaultPath,
            'intervals_configured' => $this->synchronizer->isConfigured(),
        ], new Response(status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/import/dossier', name: 'import_folder', methods: ['POST'])]
    #[IsCsrfTokenValid('import_folder')]
    public function importFolder(): Response
    {
        if ('' === $this->defaultPath) {
            $this->addFlash('error', 'Aucun dossier configuré : renseignez STRAVA_EXPORT_PATH dans .env.local.');
        } else {
            try {
                $this->flashResult($this->importer->import($this->defaultPath));
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $this->addFlash('error', $e->getMessage());
            }
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

    private function flashResult(ImportResult $result): void
    {
        $this->addFlash('success', 'Import terminé : '.$result->summary().'.');
        foreach (\array_slice($result->errors, 0, 5) as $error) {
            $this->addFlash('warning', $error);
        }
    }
}
