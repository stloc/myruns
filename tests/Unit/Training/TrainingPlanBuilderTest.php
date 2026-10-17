<?php

declare(strict_types=1);

namespace App\Tests\Unit\Training;

use App\Prediction\FitnessSnapshot;
use App\Prediction\Goal;
use App\Training\Phase;
use App\Training\PlanOptions;
use App\Training\SessionType;
use App\Training\TrainingPaces;
use App\Training\TrainingPlan;
use App\Training\TrainingPlanBuilder;
use App\Training\TrainingWeek;
use App\Training\WeekAdjustment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrainingPlanBuilderTest extends TestCase
{
    private const NOW = '2026-10-16'; // un vendredi

    public function testPacesMatchDanielsTables(): void
    {
        // Tables de Daniels, VDOT 50 : seuil 4:15/km, intervalles 3:55/km, marathon 4:31/km.
        $paces = TrainingPaces::from(50, new Goal(10000, 45 * 60, new \DateTimeImmutable()));

        self::assertEqualsWithDelta(255, $paces->threshold, 3);
        self::assertEqualsWithDelta(235, $paces->interval, 3);
        self::assertEqualsWithDelta(271, $paces->marathon, 3);
        self::assertEqualsWithDelta(270, $paces->goal, 0.01);
        self::assertGreaterThan($paces->easyFast, $paces->easySlow);
        self::assertGreaterThan($paces->marathon, $paces->easyFast);
    }

    public function testTenKilometrePlanRunsFromThisWeekToTheRace(): void
    {
        $plan = $this->plan(10000, 45 * 60, '2027-01-17', 4);

        self::assertCount(14, $plan->weeks);
        self::assertSame('2026-10-12', $plan->weeks[0]->monday->format('Y-m-d'));
        self::assertTrue($plan->weeks[0]->contains(new \DateTimeImmutable(self::NOW)));
        self::assertTrue($plan->weeks[13]->contains(new \DateTimeImmutable('2027-01-17')));

        $last = $plan->weeks[13]->sessions;
        $race = $last[\count($last) - 1];
        self::assertSame(SessionType::Race, $race->type);
        self::assertSame(10.0, $race->distanceKm);

        foreach ($plan->weeks as $week) {
            self::assertCount(4, $week->sessions, 'semaine '.$week->number);
        }
    }

    public function testPhasesFollowEachOtherWithRecoveryWeeks(): void
    {
        $plan = $this->plan(21097.5, 105 * 60, '2027-03-07', 4);
        $phases = array_map(static fn (TrainingWeek $week): Phase => $week->phase, $plan->weeks);

        self::assertSame(Phase::Base, $phases[0]);
        self::assertSame([Phase::Taper, Phase::Taper], \array_slice($phases, -2));
        $order = array_map(static fn (Phase $phase): int => array_search($phase, Phase::cases(), true) ?: 0, $phases);
        $sorted = $order;
        sort($sorted);
        self::assertSame($sorted, $order, 'foncier → développement → spécifique → affûtage');

        $recoveries = array_values(array_filter($plan->weeks, static fn (TrainingWeek $week): bool => $week->recovery));
        self::assertNotEmpty($recoveries);
        self::assertSame(4, $recoveries[0]->number);
    }

    /**
     * @return iterable<string, array{float, int, string, int}>
     */
    public static function goals(): iterable
    {
        yield '10 km, 4 séances' => [10000, 45 * 60, '2027-01-17', 4];
        yield 'semi, 3 séances' => [21097.5, 105 * 60, '2027-03-07', 3];
        yield 'marathon, 5 séances' => [42195, 225 * 60, '2027-04-11', 5];
    }

    #[DataProvider('goals')]
    public function testVolumeGrowsByTenPercentAtMostAndTapers(float $distance, int $seconds, string $date, int $sessions): void
    {
        $plan = $this->plan($distance, $seconds, $date, $sessions);

        $previous = null;
        foreach ($plan->weeks as $week) {
            if (Phase::Taper === $week->phase) {
                break;
            }
            if (null !== $previous && !$week->recovery) {
                // Arrondi des séances au demi-kilomètre : tolérance d'un kilomètre.
                self::assertLessThanOrEqual(1.1 * $previous + 1.0, $week->volumeKm(), 'semaine '.$week->number);
            }
            if (!$week->recovery) {
                $previous = $week->volumeKm();
            }
        }

        $taper = array_values(array_filter($plan->weeks, static fn (TrainingWeek $week): bool => Phase::Taper === $week->phase));
        $raceKm = $distance / 1000;
        self::assertLessThan($plan->peakVolumeKm(), $taper[0]->volumeKm());
        self::assertLessThan($plan->peakVolumeKm() - $raceKm + 1, $taper[\count($taper) - 1]->volumeKm() - $raceKm);
    }

    public function testLongRunGrowsTowardsTheDistance(): void
    {
        $plan = $this->plan(42195, 225 * 60, '2027-04-11', 5);

        self::assertGreaterThanOrEqual(28, $plan->longestRunKm());
        self::assertLessThanOrEqual(32, $plan->longestRunKm());
        $first = $plan->weeks[0]->sessions;
        self::assertSame(SessionType::Long, $first[\count($first) - 1]->type);
        self::assertLessThanOrEqual(12.5, $first[\count($first) - 1]->distanceKm);
    }

    public function testMarathonOnThreeSessionsGetsAWarning(): void
    {
        $plan = $this->plan(42195, 225 * 60, '2027-04-11', 3);

        self::assertContains('Pour un marathon, 4 séances par semaine ou plus sont conseillées.', $plan->notes);
    }

    public function testSessionsPerWeekDefaultsToRegularityAndIsClamped(): void
    {
        $builder = new TrainingPlanBuilder();

        self::assertSame(2, $builder->defaultSessionsPerWeek(self::fitness(runsLast4Weeks: 4)));
        self::assertSame(4, $builder->defaultSessionsPerWeek(self::fitness(runsLast4Weeks: 16)));
        self::assertSame(5, $builder->defaultSessionsPerWeek(self::fitness(runsLast4Weeks: 40)));
        self::assertSame(6, $this->plan(10000, 45 * 60, '2027-01-17', 9)->sessionsPerWeek);
    }

    public function testWithoutHistoryPacesComeFromTheGoal(): void
    {
        $goal = new Goal(10000, 50 * 60, new \DateTimeImmutable('2027-01-17'));
        $plan = new TrainingPlanBuilder()->build($goal, self::fitness(vdot: null), new PlanOptions(3), new \DateTimeImmutable(self::NOW));

        self::assertNotNull($plan);
        self::assertStringContainsString('allures estimées à partir de l\'objectif', $plan->notes[0]);
        self::assertGreaterThan($plan->paces->goal, $plan->paces->threshold);
    }

    public function testSessionsAreSpreadOverTheWeek(): void
    {
        $plan = $this->plan(10000, 45 * 60, '2027-01-17', 4);
        $week = $plan->weeks[5]; // développement : VMA + seuil

        self::assertSame([1, 2, 4, 7], array_map(static fn ($s): ?int => $s->weekday, $week->sessions));
        self::assertSame(SessionType::Long, $week->sessions[3]->type);
        self::assertTrue($week->sessions[1]->type->isQuality());
        self::assertTrue($week->sessions[2]->type->isQuality());
        self::assertSame('2026-11-17', $week->dateOf($week->sessions[1])?->format('Y-m-d'));
    }

    public function testClubSessionTakesItsDayAndReplacesAQualitySession(): void
    {
        $plan = $this->plan(10000, 45 * 60, '2027-01-17', 4, clubDay: 3);

        foreach (\array_slice($plan->weeks, 0, -1) as $week) {
            self::assertTrue($week->club);
            self::assertCount(4, $week->sessions, 'semaine '.$week->number);
            $club = array_values(array_filter($week->sessions, static fn ($s): bool => SessionType::Club === $s->type));
            self::assertCount(1, $club, 'semaine '.$week->number);
            self::assertSame(3, $club[0]->weekday);
            self::assertStringContainsString('À 18:40', $club[0]->description);
            $hard = array_filter($week->sessions, static fn ($s): bool => $s->type->isQuality());
            self::assertLessThanOrEqual(2, \count($hard), 'semaine '.$week->number);
            // Jamais de séance intense la veille ou le lendemain du club.
            foreach ($hard as $session) {
                self::assertNotContains($session->weekday, [2, 4], 'semaine '.$week->number);
            }
        }

        // Le club n'évince pas le travail à l'allure objectif.
        $specific = array_filter($plan->weeks, static fn (TrainingWeek $w): bool => Phase::Specific === $w->phase && !$w->recovery);
        foreach ($specific as $week) {
            self::assertContains(SessionType::GoalPace, array_map(static fn ($s): SessionType => $s->type, $week->sessions), 'semaine '.$week->number);
        }
    }

    public function testClubOnSundayMovesTheLongRunToSaturday(): void
    {
        $week = $this->plan(10000, 45 * 60, '2027-01-17', 3, clubDay: 7)->weeks[2];

        self::assertSame(SessionType::Long, $week->sessions[\count($week->sessions) - 2]->type);
        self::assertSame(6, $week->sessions[\count($week->sessions) - 2]->weekday);
    }

    public function testAWeekCanSkipTheClubAndChangeItsSessions(): void
    {
        $reference = $this->plan(10000, 45 * 60, '2027-01-17', 4, clubDay: 3);
        $plan = $this->plan(10000, 45 * 60, '2027-01-17', 4, clubDay: 3, adjustments: [
            '2026-11-16' => new WeekAdjustment(club: false),
            '2026-11-23' => new WeekAdjustment(sessions: 2),
        ]);

        $noClub = $plan->weeks[5];
        self::assertFalse($noClub->club);
        self::assertTrue($noClub->adjusted);
        self::assertNotContains(SessionType::Club, array_map(static fn ($s): SessionType => $s->type, $noClub->sessions));
        self::assertCount(4, $noClub->sessions);

        $twoRuns = $plan->weeks[6];
        self::assertSame(2, $twoRuns->plannedSessions);
        self::assertSame([SessionType::Club, SessionType::Long], array_map(static fn ($s): SessionType => $s->type, $twoRuns->sessions));
        self::assertLessThan($reference->weeks[6]->volumeKm(), $twoRuns->volumeKm());

        // Les autres semaines ne bougent pas.
        self::assertEquals($reference->weeks[7], $plan->weeks[7]);
        self::assertFalse($plan->weeks[7]->adjusted);
    }

    public function testRaceWeekPutsTheRaceOnItsDayAndEasesTheClub(): void
    {
        $plan = $this->plan(10000, 45 * 60, '2027-01-17', 4, clubDay: 3);
        $raceWeek = $plan->weeks[\count($plan->weeks) - 1];
        $last = $raceWeek->sessions[\count($raceWeek->sessions) - 1];

        self::assertSame(SessionType::Race, $last->type);
        self::assertSame(7, $last->weekday);
        $club = array_values(array_filter($raceWeek->sessions, static fn ($s): bool => SessionType::Club === $s->type));
        self::assertStringContainsString('pas de fractionné', $club[0]->description);

        // Objectif un jeudi : club du mercredi trop proche, remplacé par un footing.
        $thursday = $this->plan(10000, 45 * 60, '2027-01-14', 4, clubDay: 3);
        $raceWeek = $thursday->weeks[\count($thursday->weeks) - 1];
        self::assertFalse($raceWeek->club);
        self::assertNotContains(SessionType::Club, array_map(static fn ($s): SessionType => $s->type, $raceWeek->sessions));
    }

    public function testTwoSessionsPerWeekAreAllowed(): void
    {
        $plan = $this->plan(10000, 45 * 60, '2027-01-17', 2);

        self::assertSame(2, $plan->sessionsPerWeek);
        self::assertCount(2, $plan->weeks[5]->sessions);
        self::assertContains('Avec 2 sorties par semaine, la progression reste limitée : ajoutez un footing dès que possible.', $plan->notes);
    }

    public function testNoPlanForAPastGoal(): void
    {
        $goal = new Goal(10000, 45 * 60, new \DateTimeImmutable(self::NOW));

        self::assertNull(new TrainingPlanBuilder()->build($goal, self::fitness(), new PlanOptions(4), new \DateTimeImmutable(self::NOW)));
    }

    /**
     * @param array<string, WeekAdjustment> $adjustments
     */
    private function plan(float $distance, int $seconds, string $date, int $sessions, ?int $clubDay = null, array $adjustments = []): TrainingPlan
    {
        $options = new PlanOptions($sessions, $clubDay, null === $clubDay ? null : '18:40', $adjustments);
        $plan = new TrainingPlanBuilder()->build(new Goal($distance, $seconds, new \DateTimeImmutable($date)), self::fitness(), $options, new \DateTimeImmutable(self::NOW));
        self::assertNotNull($plan);

        return $plan;
    }

    private static function fitness(?float $vdot = 45.0, int $runsLast4Weeks = 12): FitnessSnapshot
    {
        $now = new \DateTimeImmutable(self::NOW);

        return new FitnessSnapshot($now, $vdot, 0.05, 20, $runsLast4Weeks, 25.0, 22.0, 11.0, [], [], $now);
    }
}
