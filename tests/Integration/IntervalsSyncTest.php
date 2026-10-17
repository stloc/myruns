<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Activity;
use App\Enum\SplitKind;
use App\Enum\Sport;
use App\Import\ActivityImporter;
use App\Repository\ActivityRepository;
use App\Repository\AthleteProfileRepository;
use App\Sync\IntervalsClient;
use App\Sync\IntervalsSynchronizer;
use App\Tests\Fit\FitFileBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Synchro intervals.icu contre un faux serveur (aucun appel réseau).
 */
final class IntervalsSyncTest extends KernelTestCase
{
    private const START = '2026-10-14T16:47:05Z';

    /** @var list<string> URL appelées */
    private array $calls = [];

    public function testCreatesActivityWithSplitsSkipsStravaAndIsIdempotent(): void
    {
        self::bootKernel();
        $synchronizer = $this->synchronizer(restingHeartRates: [52, 50, 51, null]);

        $first = $synchronizer->sync(new \DateTimeImmutable('2026-10-10'), new \DateTimeImmutable('2026-10-16'));

        self::assertSame(1, $first->import->created);
        self::assertSame(1, $first->import->ignored, 'Séance venue de Strava ignorée');
        self::assertSame(1, $first->import->withSplits);
        self::assertSame(51, $first->restingHeartRate);

        $activity = self::getContainer()->get(ActivityRepository::class)->find('intervals:i1');
        self::assertInstanceOf(Activity::class, $activity);
        self::assertSame(Sport::TrailRun, $activity->sport);
        self::assertSame('Lunch Trail', $activity->name);
        self::assertCount(2, $activity->splitsOf(SplitKind::Kilometer));
        self::assertSame(51, self::getContainer()->get(AthleteProfileRepository::class)->current()->restingHeartRate);
        self::assertContains('https://intervals.icu/api/v1/activity/i1/file', $this->calls);

        // Seconde synchro : rien de nouveau, et le fichier n'est pas retéléchargé.
        $this->calls = [];
        $second = $this->synchronizer(restingHeartRates: [])->sync(new \DateTimeImmutable('2026-10-10'), new \DateTimeImmutable('2026-10-16'));
        self::assertSame(0, $second->import->created);
        self::assertSame(1, $second->import->unchanged);
        self::assertNotContains('https://intervals.icu/api/v1/activity/i1/file', $this->calls);
    }

    public function testKnownSessionFromStravaArchiveIsNotDuplicated(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(ActivityRepository::class);
        $repository->upsert(new Activity('20585706186', ActivityRepository::SOURCE_CSV, 'Course à pied en soirée', Sport::Run, new \DateTimeImmutable(self::START), 7960, 2537, 4207));
        $repository->flush();

        $result = $this->synchronizer(restingHeartRates: [])->sync(new \DateTimeImmutable('2026-10-10'), new \DateTimeImmutable('2026-10-16'));

        self::assertSame(0, $result->import->created);
        self::assertSame(1, $result->import->unchanged);
        self::assertSame(1, $repository->count(), 'La ligne du CSV est conservée, sans doublon');
        // La trace intervals.icu complète les temps de passage manquants de l'activité du CSV.
        $csvActivity = $repository->find('20585706186');
        self::assertInstanceOf(Activity::class, $csvActivity);
        self::assertTrue($csvActivity->hasSplits());
    }

    /**
     * @param list<?int> $restingHeartRates
     */
    private function synchronizer(array $restingHeartRates): IntervalsSynchronizer
    {
        $fit = (new FitFileBuilder())->session(1);
        $start = (new \DateTimeImmutable(self::START))->getTimestamp();
        for ($t = 0; $t <= 600; ++$t) {
            $fit->record($start + $t, $t * 3.0, 150);
        }

        $http = new MockHttpClient(function (string $method, string $url) use ($fit, $restingHeartRates) {
            $this->calls[] = strtok($url, '?') ?: $url;

            return match (true) {
                str_contains($url, '/activities?') => new JsonMockResponse([
                    ['id' => 'i1', 'start_date' => self::START, 'name' => 'Lunch Trail', 'type' => 'TrailRun', 'source' => 'SUUNTO', 'file_type' => 'fit',
                        'distance' => 1800.0, 'moving_time' => 600, 'elapsed_time' => 600, 'average_heartrate' => 150, 'max_heartrate' => 150],
                    ['id' => 'i2', 'start_date' => '2026-10-12T08:00:00Z', 'name' => 'Via Strava', 'type' => 'Run', 'source' => 'STRAVA', 'file_type' => 'fit',
                        'distance' => 5000.0, 'moving_time' => 1500, 'elapsed_time' => 1500],
                ]),
                str_contains($url, '/wellness?') => new JsonMockResponse(array_map(
                    static fn (?int $hr, int $i): array => ['id' => \sprintf('2026-10-%02d', $i + 1), 'restingHR' => $hr],
                    $restingHeartRates,
                    array_keys($restingHeartRates),
                )),
                str_ends_with($url, '/activity/i1/file') => new MockResponse($fit->build()),
                default => new MockResponse('', ['http_code' => 404]),
            };
        }, 'https://intervals.icu');

        $container = self::getContainer();

        return new IntervalsSynchronizer(
            new IntervalsClient($http, 'clé-de-test', '0'),
            $container->get(ActivityRepository::class),
            $container->get(AthleteProfileRepository::class),
            $container->get(ActivityImporter::class),
        );
    }
}
