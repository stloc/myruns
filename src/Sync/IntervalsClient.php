<?php

declare(strict_types=1);

namespace App\Sync;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client de l'API intervals.icu (lecture seule) : séances et données de santé de l'athlète.
 *
 * @see https://forum.intervals.icu/t/intervals-icu-api-integration-cookbook/80090
 */
final class IntervalsClient
{
    public function __construct(
        #[Target('intervals.client')] private readonly HttpClientInterface $client,
        #[Autowire('%env(INTERVALS_API_KEY)%')] private readonly string $apiKey,
        #[Autowire('%env(INTERVALS_ATHLETE_ID)%')] private readonly string $athleteId,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->apiKey);
    }

    /**
     * @return list<array<string, mixed>> résumé de chaque séance, de la plus récente à la plus ancienne
     */
    public function activities(\DateTimeImmutable $oldest, \DateTimeImmutable $newest): array
    {
        return $this->getList(\sprintf('/api/v1/athlete/%s/activities', $this->athlete()), [
            'oldest' => $oldest->format('Y-m-d'),
            'newest' => $newest->format('Y-m-d'),
        ]);
    }

    /**
     * Fichier d'origine de la séance (FIT, GPX ou TCX, parfois compressé en gzip).
     */
    public function originalFile(string $activityId): string
    {
        return $this->client->request('GET', \sprintf('/api/v1/activity/%s/file', rawurlencode($activityId)))->getContent();
    }

    /**
     * @return list<array<string, mixed>> une entrée par jour (FC de repos, poids…)
     */
    public function wellness(\DateTimeImmutable $oldest, \DateTimeImmutable $newest): array
    {
        return $this->getList(\sprintf('/api/v1/athlete/%s/wellness', $this->athlete()), [
            'oldest' => $oldest->format('Y-m-d'),
            'newest' => $newest->format('Y-m-d'),
        ]);
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<array<string, mixed>>
     */
    private function getList(string $path, array $query): array
    {
        $data = $this->client->request('GET', $path, ['query' => $query])->toArray();

        $items = [];
        foreach ($data as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $entry = [];
            foreach ($item as $key => $value) {
                if (\is_string($key)) {
                    $entry[$key] = $value;
                }
            }
            $items[] = $entry;
        }

        return $items;
    }

    /** « 0 » désigne l'athlète propriétaire de la clé. */
    private function athlete(): string
    {
        return '' !== trim($this->athleteId) ? rawurlencode(trim($this->athleteId)) : '0';
    }
}
