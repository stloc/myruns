<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\SettingsType;
use App\Settings\LocalSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly LocalSettings $settings,
    ) {
    }

    #[Route('/reglages', name: 'settings', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $form = $this->createForm(SettingsType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $values = [];
            foreach (LocalSettings::KEYS as $key) {
                $value = $form->get($key)->getData();
                if (\is_string($value) && '' !== trim($value)) {
                    $values[$key] = $value;
                }
            }
            $this->settings->save($values);
            $this->addFlash('success', [] === $values ? 'Aucune clé modifiée.' : 'Clés enregistrées.');

            return $this->redirectToRoute('settings');
        }

        $keys = [];
        foreach (LocalSettings::KEYS as $key) {
            $keys[$key] = [
                'from_environment' => '' !== $this->settings->environmentValue($key),
                'stored' => self::mask($this->settings->get($key)),
            ];
        }

        return $this->render('settings/index.html.twig', [
            'form' => $form,
            'keys' => $keys,
        ], new Response(status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/reglages/{key}/effacer', name: 'settings_clear', methods: ['POST'])]
    #[IsCsrfTokenValid('settings_clear')]
    public function clear(string $key): Response
    {
        if (!\in_array($key, LocalSettings::KEYS, true)) {
            throw new NotFoundHttpException();
        }

        $this->settings->save([$key => '']);
        $this->addFlash('success', 'Clé effacée.');

        return $this->redirectToRoute('settings');
    }

    /**
     * « …abcd » : de quoi reconnaître la clé sans l'afficher.
     */
    private static function mask(string $value): string
    {
        return '' === $value ? '' : '…'.substr($value, -4);
    }
}
