<?php

declare(strict_types=1);

namespace App\Tests\Unit\Training;

use App\Entity\Activity;
use App\Enum\SplitKind;
use App\Enum\Sport;
use App\Prediction\Goal;
use App\Prediction\HeartRateReserve;
use App\Training\Phase;
use App\Training\SessionEvaluator;
use App\Training\SessionOutcome;
use App\Training\SessionReview;
use App\Training\SessionType;
use App\Training\TrainingPaces;
use App\Training\TrainingSession;
use App\Training\TrainingWeek;
use App\Training\WeekReviewer;
use PHPUnit\Framework\TestCase;

/**
 * Bilan d'une séance : plus long que prévu à la bonne intensité est positif ; seule l'intensité
 * (trop élevée en endurance, absente en qualité) donne un avertissement.
 */
final class SessionEvaluatorTest extends TestCase
{
    private const DAY = '2026-10-18';
    private const TODAY = '2026-10-18 20:00';

    private TrainingPaces $paces;

    protected function setUp(): void
    {
        // VDOT 45 : footing ≈ 5:28–6:03/km, allure marathon ≈ 4:33/km.
        $this->paces = TrainingPaces::from(45.0, new Goal(10000, 45 * 60, new \DateTimeImmutable('2026-12-20')));
    }

    public function testLongRunMuchLongerAtEasyPaceIsLargelyAchieved(): void
    {
        $review = $this->evaluate(new TrainingSession(SessionType::Long, 'Sortie longue', 8.0), [$this->runOf(12.0, $this->paces->easySlow)]);

        self::assertSame(SessionOutcome::Exceeded, $review->outcome);
        self::assertSame('success', $review->outcome->tone());
        self::assertSame('Objectif de la séance largement atteint : 12,0 km en endurance au lieu de 8,0. Les semaines suivantes en tiennent compte.', $review->advice);
    }

    public function testDistanceWithinTwentyPercentIsDone(): void
    {
        $review = $this->evaluate(new TrainingSession(SessionType::Easy, 'Footing', 8.0), [$this->runOf(9.5, $this->paces->easy())]);

        self::assertSame(SessionOutcome::Done, $review->outcome);
    }

    public function testEasyRunFasterThanItsZoneIsTooHard(): void
    {
        $review = $this->evaluate(new TrainingSession(SessionType::Easy, 'Footing', 8.0), [$this->runOf(8.0, $this->paces->easyFast - 30)]);

        self::assertSame(SessionOutcome::TooHard, $review->outcome);
        self::assertStringContainsString('plus vite que votre zone d\'endurance', (string) $review->advice);

        // Juste sous l'allure de footing la plus rapide : toléré.
        $tolerated = $this->evaluate(new TrainingSession(SessionType::Easy, 'Footing', 8.0), [$this->runOf(8.0, $this->paces->easyFast - 10)]);
        self::assertSame(SessionOutcome::Done, $tolerated->outcome);
    }

    public function testHeartRateTakesPrecedenceOverPace(): void
    {
        $heartRate = new HeartRateReserve(190, 50);
        $session = new TrainingSession(SessionType::Easy, 'Footing', 8.0);

        // Rapide mais FC à 71 % de la réserve : forme meilleure que l'indice, séance facile.
        $fastButEasy = $this->evaluate($session, [$this->runOf(8.0, $this->paces->easyFast - 40, heartRate: 150.0)], $heartRate);
        self::assertSame(SessionOutcome::Done, $fastButEasy->outcome);

        // Lent mais FC à 86 % de la réserve (chaleur, fatigue) : trop intense.
        $slowButHard = $this->evaluate($session, [$this->runOf(8.0, $this->paces->easySlow, heartRate: 170.0)], $heartRate);
        self::assertSame(SessionOutcome::TooHard, $slowButHard->outcome);
        self::assertStringContainsString('86 % de votre réserve cardiaque', (string) $slowButHard->advice);
    }

    public function testTrailPaceIsNotJudged(): void
    {
        $review = $this->evaluate(new TrainingSession(SessionType::Long, 'Sortie longue', 10.0), [$this->runOf(10.0, $this->paces->easyFast - 60, Sport::TrailRun)]);

        self::assertSame(SessionOutcome::Done, $review->outcome);
    }

    public function testShortRunIsPartial(): void
    {
        $review = $this->evaluate(new TrainingSession(SessionType::Long, 'Sortie longue', 12.0), [$this->runOf(8.0, $this->paces->easySlow)]);

        self::assertSame(SessionOutcome::Partial, $review->outcome);
        self::assertStringStartsWith('Séance écourtée : 8,0 km sur 12,0 prévus. La sortie longue', (string) $review->advice);
    }

    public function testSessionWithoutRunIsMissedOnceItsDayIsOver(): void
    {
        $session = new TrainingSession(SessionType::Long, 'Sortie longue', 12.0);

        self::assertSame(SessionOutcome::Upcoming, $this->evaluate($session, [])->outcome);

        $missed = new SessionEvaluator()->evaluate($session, new \DateTimeImmutable(self::DAY), [], $this->paces, null, new \DateTimeImmutable('2026-10-19 08:00'));
        self::assertSame(SessionOutcome::Missed, $missed->outcome);
        self::assertStringStartsWith('Sortie longue manquée', (string) $missed->advice);
    }

    public function testQualitySessionNeedsOneSplitAtTheTargetPace(): void
    {
        $session = new TrainingSession(SessionType::Threshold, 'Seuil', 9.0);

        $jog = $this->runOf(9.0, $this->paces->easy(), laps: [[2000.0, $this->paces->easy()], [5000.0, $this->paces->easy()], [2000.0, $this->paces->easy()]]);
        self::assertSame(SessionOutcome::TooEasy, $this->evaluate($session, [$jog])->outcome);

        $work = $this->runOf(9.0, $this->paces->easy() - 20, laps: [[2000.0, $this->paces->easy()], [5000.0, $this->paces->threshold], [2000.0, $this->paces->easy()]]);
        self::assertSame(SessionOutcome::Done, $this->evaluate($session, [$work])->outcome);

        // Sans temps de passage, l'intensité n'est pas jugée.
        self::assertSame(SessionOutcome::Done, $this->evaluate($session, [$this->runOf(9.0, $this->paces->easy())])->outcome);
    }

    public function testRunsOfTheSameDayAreAddedUp(): void
    {
        $review = $this->evaluate(new TrainingSession(SessionType::Easy, 'Footing', 8.0), [$this->runOf(4.0, $this->paces->easy()), $this->runOf(4.2, $this->paces->easy())]);

        self::assertSame(SessionOutcome::Done, $review->outcome);
        self::assertEqualsWithDelta(8.2, $review->doneKm(), 0.001);
    }

    public function testRunsAreMatchedToTheirDayThenToAShiftedSession(): void
    {
        $week = new TrainingWeek(1, new \DateTimeImmutable('2026-10-12'), Phase::Base, false, [
            new TrainingSession(SessionType::Easy, 'Footing', 6.0, 2),
            new TrainingSession(SessionType::Threshold, 'Seuil', 8.0, 4),
            new TrainingSession(SessionType::Long, 'Sortie longue', 12.0, 7),
        ]);
        $tuesday = $this->runOf(6.0, $this->paces->easy(), day: '2026-10-13');
        $friday = $this->runOf(8.0, $this->paces->easy(), day: '2026-10-16');
        $saturday = $this->runOf(5.0, $this->paces->easy(), day: '2026-10-17');

        $review = new WeekReviewer()->review($week, [$tuesday, $friday, $saturday], $this->paces, null, new \DateTimeImmutable('2026-10-17 20:00'));

        self::assertSame([$tuesday], $review->sessions[0]->activities);
        // Séance de jeudi faite vendredi.
        self::assertSame([$friday], $review->sessions[1]->activities);
        // La sortie longue de dimanche peut encore être faite : le footing du samedi est hors programme.
        self::assertSame(SessionOutcome::Upcoming, $review->sessions[2]->outcome);
        self::assertSame([$saturday], $review->extras);
        self::assertEqualsWithDelta(19.0, $review->doneKm(), 0.001);
        self::assertNull($review->advice);
    }

    public function testWeekFarAboveThePlanSuggestsAnEasyNextWeek(): void
    {
        $week = new TrainingWeek(1, new \DateTimeImmutable('2026-10-12'), Phase::Base, false, [
            new TrainingSession(SessionType::Easy, 'Footing', 6.0, 2),
            new TrainingSession(SessionType::Long, 'Sortie longue', 10.0, 7),
        ]);

        $review = new WeekReviewer()->review($week, [
            $this->runOf(10.0, $this->paces->easy(), day: '2026-10-13'),
            $this->runOf(15.0, $this->paces->easySlow, day: '2026-10-18'),
        ], $this->paces, null, new \DateTimeImmutable('2026-10-18 20:00'));

        self::assertSame('Semaine à 25,0 km pour 16,0 prévus : bien au-dessus du programme. Si la fatigue se fait sentir, gardez les footings de la semaine suivante vraiment faciles.', $review->advice);
    }

    /**
     * @param list<Activity> $runs
     */
    private function evaluate(TrainingSession $session, array $runs, ?HeartRateReserve $heartRate = null): SessionReview
    {
        return new SessionEvaluator()->evaluate($session, new \DateTimeImmutable(self::DAY), $runs, $this->paces, $heartRate, new \DateTimeImmutable(self::TODAY));
    }

    /**
     * @param list<array{0: float, 1: float}> $laps distance (m) et allure (s/km) de chaque tour
     */
    private function runOf(float $km, float $pace, Sport $sport = Sport::Run, ?float $heartRate = null, array $laps = [], string $day = self::DAY): Activity
    {
        $seconds = (int) round($km * $pace);
        $run = new Activity(bin2hex(random_bytes(4)), 'test', 'Sortie', $sport, new \DateTimeImmutable($day.' 08:00'), $km * 1000, $seconds, $seconds, averageHeartRate: $heartRate);
        $run->replaceTrackData(array_map(static fn (array $lap): array => [
            'kind' => SplitKind::Lap,
            'distance' => $lap[0],
            'seconds' => $lap[0] / 1000 * $lap[1],
            'avg_hr' => null,
            'max_hr' => null,
            'elevation_gain' => null,
        ], $laps), null);

        return $run;
    }
}
