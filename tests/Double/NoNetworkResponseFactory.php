<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Réponse de tout client HTTP du conteneur en test : service indisponible, jamais d'appel réseau.
 */
final class NoNetworkResponseFactory
{
    public function __invoke(string $method, string $url): MockResponse
    {
        return new MockResponse('', ['http_code' => 503]);
    }
}
