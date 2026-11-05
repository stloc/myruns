<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backup\BackupException;
use App\Backup\DatabaseBackup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

final class BackupController extends AbstractController
{
    public function __construct(private readonly DatabaseBackup $backup)
    {
    }

    #[Route('/sauvegarde/export', name: 'backup_export', methods: ['POST'])]
    #[IsCsrfTokenValid('backup_export')]
    public function export(Request $request): Response
    {
        try {
            $path = $this->backup->export($request->request->getBoolean('with_settings'));
        } catch (BackupException $e) {
            $this->addFlash('error', 'Export impossible : '.$e->getMessage());

            return $this->redirectToRoute('import');
        }

        return $this->file($path, \sprintf('myruns-sauvegarde-%s.zip', date('Y-m-d')), ResponseHeaderBag::DISPOSITION_ATTACHMENT)
            ->deleteFileAfterSend();
    }

    #[Route('/sauvegarde/import', name: 'backup_import', methods: ['POST'])]
    #[IsCsrfTokenValid('backup_import')]
    public function import(Request $request): Response
    {
        $file = $request->files->get('backup');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', 'Choisissez le fichier de sauvegarde (.zip).');
        } elseif (!$request->request->getBoolean('confirm')) {
            $this->addFlash('error', 'Cochez la confirmation : la restauration remplace toutes les données actuelles.');
        } else {
            try {
                $withSettings = $this->backup->restore($file->getPathname(), $request->request->getBoolean('with_settings'));
                $this->addFlash('success', 'Sauvegarde restaurée'.($withSettings ? ', clés d\'API comprises' : '').'. L\'ancienne base est conservée à côté (myruns.db.bak).');
            } catch (BackupException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('import');
    }
}
