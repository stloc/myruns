<?php

declare(strict_types=1);

namespace App\Tests\Unit\Training;

use App\Prediction\FitnessSnapshot;
use App\Prediction\Goal;
use App\Training\Phase;
use App\Training\PlanOptions;
use App\Training\SessionEdit;
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

    public function testAWeekCanSwapDaysAndChangeItsSessions(): void
    {
        $reference = $this->plan(10000, 45 * 60, '2027-01-17', 4);
        $plan = $this->plan(10000, 45 * 60, '2027-01-17', 4, [
            // Séance du mardi au mercredi (jour de repos), sortie longue au samedi.
            '2026-11-16' => new WeekAdjustment(days: [2 => 3, 3 => 2, 6 => 7, 7 => 6]),
            '2026-11-23' => new WeekAdjustment(sessions: 2),
        ]);

        $moved = $plan->weeks[5];
        $before = $reference->weeks[5];
        self::assertTrue($moved->adjusted);
        self::assertSame([1, 3, 4, 6], array_map(static fn ($s): ?int => $s->weekday, $moved->sessions));
        self::assertSame($before->sessions[1]->description, $moved->sessions[1]->description);
        self::assertSame(SessionType::Long, $moved->sessionOn(6)?->type);
        self::assertNull($moved->sessionOn(2));
        self::assertSame($before->volumeKm(), $moved->volumeKm());

        $twoRuns = $plan->weeks[6];
        self::assertSame(2, $twoRuns->plannedSessions);
        self::assertCount(2, $twoRuns->sessions);
        self::assertSame(SessionType::Long, $twoRuns->sessions[1]->type);
        self::assertLessThan($reference->weeks[6]->volumeKm(), $twoRuns->volumeKm());

        // Les autres semaines ne bougent pas.
        self::assertEquals($reference->weeks[7], $plan->weeks[7]);
        self::assertFalse($plan->weeks[7]->adjusted);
    }

    public function testAnEditedSessionReplacesThePlannedOneAndFollowsItsDay(): void
    {
        $reference = $this->plan(10000, 45 * 60, '2027-01-17', 4);
        $planned = $reference->weeks[5]->sessions[1]; // mardi
        $plan = $this->plan(10000, 45 * 60, '2027-01-17', 4, [
            '2026-11-16' => new WeekAdjustment(days: [2 => 3, 3 => 2], edits: [2 => new SessionEdit(SessionType::Hills, 'Club : 10 × 200 m en côte', 9.5)]),
        ]);

        $week = $plan->weeks[5];
        $edited = $week->sessionOn(3);
        self::assertNotNull($edited);
        self::assertNotSame($planned->type, $edited->type);
        self::assertSame(SessionType::Hills, $edited->type);
        self::assertSame('Club : 10 × 200 m en côte', $edited->description);
        self::assertTrue($edited->edited);
        self::assertTrue($week->adjusted);
        self::assertEqualsWithDelta($reference->weeks[5]->volumeKm() - $planned->distanceKm + 9.5, $week->volumeKm(), 0.01);
        self::assertFalse($week->sessions[0]->edited);
    }

    public function testRaceWeekKeepsTheRaceOnItsDay(): void
    {
        $reference = $this->plan(10000, 45 * 60, '2027-01-17', 4);
        $raceWeek = $reference->weeks[\count($reference->weeks) - 1];
        $last = $raceWeek->sessions[\count($raceWeek->sessions) - 1];
        self::assertSame(SessionType::Race, $last->type);
        self::assertSame(7, $last->weekday);

        // Un échange qui déplacerait l'objectif est ignoré : sa date est fixée.
        $plan = $this->plan(10000, 45 * 60, '2027-01-17', 4, [$raceWeek->monday->format('Y-m-d') => new WeekAdjustment(days: [5 => 7, 7 => 5])]);
        self::assertEquals($raceWeek->sessions, $plan->weeks[\count($plan->weeks) - 1]->sessions);

        // L'objectif ne se réécrit pas non plus.
        $plan = $this->plan(10000, 45 * 60, '2027-01-17', 4, [$raceWeek->monday->format('Y-m-d') => new WeekAdjustment(edits: [7 => new SessionEdit(SessionType::Easy, 'Footing', 5.0)])]);
        self::assertSame(SessionType::Race, $plan->weeks[\count($plan->weeks) - 1]->sessionOn(7)?->type);
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
    private function plan(float $distance, int $seconds, string $date, int $sessions, array $adjustments = []): TrainingPlan
    {
        $options = new PlanOptions($sessions, $adjustments);
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
