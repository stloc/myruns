<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Import\ActivityImporter;
use App\Repository\ActivityRepository;
use App\Repository\AthleteProfileRepository;
use App\Tests\StravaExportFactory;
use App\Training\PlanReview;
use App\Training\PlanReviewer;
use App\Training\SessionOutcome;
use App\Training\SessionReview;
use App\Training\SessionType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Bilan prévu / réalisé : le prévu de la semaine est figé au lundi, les semaines suivantes suivent
 * les sorties faites. Coureur type de StravaExportFactory (3 sorties par semaine, sortie longue 14 km).
 */
final class PlanReviewTest extends KernelTestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();
        // Dimanche soir : la sortie longue de la semaine (prévue le dimanche) est faite ou manquée.
        $this->now = new \DateTimeImmutable('monday this week')->modify('-1 week')->modify('+6 days')->setTime(20, 0);

        $directory = sys_get_temp_dir().'/myruns-review-'.bin2hex(random_bytes(4));
        StravaExportFactory::create($directory, $this->now);
        self::getContainer()->get(ActivityImporter::class)->import($directory);
        (new Filesystem())->remove($directory);
    }

    public function testNothingWithoutGoal(): void
    {
        self::assertNull(self::getContainer()->get(PlanReviewer::class)->review($this->now));
    }

    public function testLongerLongRunAtEasyPaceIsLargelyAchievedAndUpdatesNextWeek(): void
    {
        $this->rememberGoal();
        $before = $this->review();
        $long = $this->longRun($before);
        // Dimanche, pas encore courue : elle peut encore être faite.
        self::assertSame(SessionOutcome::Upcoming, $long->outcome);

        // Sortie longue du dimanche, 1,5 fois plus longue que prévu, en endurance (6:10/km).
        $distance = $long->session->distanceKm * 1.5;
        $this->importRun(9001, $this->now->setTime(9, 0), $distance, (int) round($distance * 370));
        $after = $this->review();

        // Le prévu de la semaine ne bouge pas.
        self::assertEquals($before->currentWeek->week, $after->currentWeek->week);

        $run = self::getContainer()->get(ActivityRepository::class)->find('9001');
        self::assertNotNull($run);
        $session = $after->sessionFor($run);
        self::assertNotNull($session);
        self::assertSame(SessionType::Long, $session->session->type);
        self::assertSame(SessionOutcome::Exceeded, $session->outcome);
        self::assertSame($session, $after->latestDone());

        // La semaine prochaine suit la sortie faite.
        $nextBefore = $before->plan->weeks[1] ?? null;
        $nextAfter = $after->plan->weeks[1] ?? null;
        self::assertNotNull($nextBefore);
        self::assertNotNull($nextAfter);
        self::assertGreaterThan($nextBefore->volumeKm(), $nextAfter->volumeKm());
        self::assertNotEmpty(array_filter($after->changes, static fn (string $change): bool => str_starts_with($change, 'Semaine prochaine : ')));
        self::assertNotNull($after->previousWeek);
    }

    private function longRun(PlanReview $review): SessionReview
    {
        foreach ($review->currentWeek->sessions as $session) {
            if (SessionType::Long === $session->session->type) {
                self::assertSame(7, $session->session->weekday);

                return $session;
            }
        }
        self::fail('Pas de sortie longue cette semaine.');
    }

    private function review(): PlanReview
    {
        $review = self::getContainer()->get(PlanReviewer::class)->review($this->now);
        self::assertNotNull($review);

        return $review;
    }

    private function rememberGoal(): void
    {
        $profiles = self::getContainer()->get(AthleteProfileRepository::class);
        $profile = $profiles->current();
        $profile->rememberGoal('semi', '1:45:00', $this->now->modify('+12 weeks')->setTime(0, 0));
        $profiles->save($profile);
    }

    private function importRun(int $id, \DateTimeImmutable $date, float $km, int $seconds): void
    {
        $directory = sys_get_temp_dir().'/myruns-review-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($directory);
        $handle = fopen($directory.'/activities.csv', 'w');
        \assert(false !== $handle);
        fputcsv($handle, StravaExportFactory::HEADER, escape: '');
        $utc = $date->setTimezone(new \DateTimeZone('UTC'));
        fputcsv($handle, [$id, $utc->format('M j, Y, g:i:s A'), 'Sortie longue', 'Run', '', $seconds, number_format($km, 2, '.', ''), 160, 30, 'false', '', $seconds, $seconds, $km * 1000, 0, ''], escape: '');
        fclose($handle);

        self::getContainer()->get(ActivityImporter::class)->import($directory);
        (new Filesystem())->remove($directory);
    }
}
