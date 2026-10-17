<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\RaceResult;
use App\Import\ActivityImporter;
use App\Prediction\FitnessAnalyzer;
use App\Prediction\FitnessSnapshot;
use App\Prediction\Goal;
use App\Prediction\GoalPredictor;
use App\Prediction\Prediction;
use App\Prediction\RunningExperience;
use App\Repository\AthleteProfileRepository;
use App\Repository\RaceResultRepository;
use App\Tests\StravaExportFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Effet de chaque information du profil sur l'estimation de forme et la prédiction.
 * Coureur type : uniquement des footings de 10 km à 6:00/km, FC moyenne 140.
 */
final class ProfileImpactTest extends KernelTestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->now = new \DateTimeImmutable();

        $directory = sys_get_temp_dir().'/mystrava-profile-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($directory);

        $path = $directory.'/activities.csv';
        $handle = fopen($path, 'w');
        \assert(false !== $handle);
        fputcsv($handle, StravaExportFactory::HEADER, escape: '');
        foreach (range(40, 1, 2) as $i => $daysAgo) {
            $date = $this->now->setTimezone(new \DateTimeZone('UTC'))->modify(\sprintf('-%d days', $daysAgo))->setTime(7, 0);
            fputcsv($handle, [2000 + $i, $date->format('M j, Y, g:i:s A'), 'Footing', 'Run', '', 3630, '10.00', 160, 30, 'false', '', 3630, 3600, 10000, 0, 140], escape: '');
        }
        fclose($handle);

        self::getContainer()->get(ActivityImporter::class)->import($directory);
        (new Filesystem())->remove($directory);
    }

    public function testHeartRateRevealsPotentialHiddenByEasyRuns(): void
    {
        $before = $this->analyze();
        $this->saveProfile(maxHeartRate: 190, restingHeartRate: 50);
        $after = $this->analyze();

        // Allure seule : VDOT ≈ 32. Avec la FC (64 % de la réserve), VDOT cardiaque ≈ 42,6, retenu à 70 %.
        self::assertEqualsWithDelta(32.3, (float) $before->vdot, 0.5);
        self::assertEqualsWithDelta(42.6, (float) $after->heartRateVdot, 0.5);
        self::assertEqualsWithDelta(39.5, (float) $after->vdot, 0.5);
        self::assertSame(20, $after->heartRateRuns);
    }

    public function testRecentRaceIsALowerBound(): void
    {
        self::getContainer()->get(RaceResultRepository::class)->save(new RaceResult($this->now->modify('-10 days'), 10000, 45 * 60, '10 km de Brest'));

        $fitness = $this->analyze();

        self::assertEqualsWithDelta(45.3, (float) $fitness->raceVdot, 0.2);
        self::assertSame((float) $fitness->raceVdot, $fitness->vdot);
        self::assertSame('10 km de Brest', $fitness->bestRace?->name);
    }

    public function testRecentRaceTakesPrecedenceOverHeartRateEstimate(): void
    {
        $this->saveProfile(maxHeartRate: 190, restingHeartRate: 50);
        self::getContainer()->get(RaceResultRepository::class)->save(new RaceResult($this->now->modify('-20 days'), 10000, 55 * 60));

        $fitness = $this->analyze();

        // Course : VDOT 35,77 moins 0,12 d'ancienneté ; l'estimation cardiaque (≈ 42,6) est calculée mais pas retenue.
        self::assertEqualsWithDelta(42.6, (float) $fitness->heartRateVdot, 0.5);
        self::assertEqualsWithDelta(35.65, (float) $fitness->vdot, 0.05);
    }

    public function testAgeSlowsProjectedProgress(): void
    {
        $goal = new Goal(10000, 55 * 60, $this->now->modify('+16 weeks'));
        $young = $this->predict($goal);
        $this->saveProfile(birthYear: (int) $this->now->format('Y') - 55);
        $older = $this->predict($goal);

        self::assertLessThan($young->projectedVdot, $older->projectedVdot);
        self::assertLessThanOrEqual($young->probabilityPercent, $older->probabilityPercent);
        self::assertStringContainsString('âge', implode(' ', $older->factors));
    }

    public function testPlannedWeightLossRaisesProjectionWithinCap(): void
    {
        $goal = new Goal(10000, 55 * 60, $this->now->modify('+16 weeks'));
        $baseline = $this->predict($goal);
        $this->saveProfile(weightKg: 80.0, targetWeightKg: 60.0);
        $lighter = $this->predict($goal);

        // Effet théorique +16,7 %, retenu à moitié et plafonné à +3 %.
        self::assertEqualsWithDelta($baseline->projectedVdot * 1.03, $lighter->projectedVdot, 0.05);
    }

    public function testShortHistoryTellsNothingAboutExperience(): void
    {
        // 40 jours d'historique : impossible de savoir si l'on débute, aucun effet.
        self::assertNull(self::getContainer()->get(RunningExperience::class)->inferredSinceYear($this->now));
        self::assertStringNotContainsString('Ancienneté', implode(' ', $this->predict(new Goal(10000, 55 * 60, $this->now->modify('+16 weeks')))->factors));
    }

    public function testLongHistoryGivesAMinimumExperience(): void
    {
        $goal = new Goal(10000, 55 * 60, $this->now->modify('+16 weeks'));
        $baseline = $this->predict($goal);
        $firstRun = $this->now->modify('-8 years');
        $this->importRun(1000, $firstRun);

        $inferred = $this->predict($goal);
        $year = (int) $firstRun->format('Y');

        // Plus de 5 ans de pratique : progression ×0,8, comme si l'année avait été saisie.
        self::assertSame($year, self::getContainer()->get(RunningExperience::class)->inferredSinceYear($this->now));
        self::assertLessThan($baseline->projectedVdot, $inferred->projectedVdot);
        self::assertContains(\sprintf('Ancienneté déduite de l\'historique : course depuis %d au moins (à préciser dans le profil)', $year), $inferred->factors);

        $this->saveProfile(runningSinceYear: $year);
        $entered = $this->predict($goal);
        self::assertSame($entered->projectedVdot, $inferred->projectedVdot);
        self::assertSame($entered->probabilityPercent, $inferred->probabilityPercent);
        self::assertStringNotContainsString('Ancienneté déduite', implode(' ', $entered->factors));

        // L'année saisie l'emporte sur l'historique (coureur débutant il y a un an, archive importée d'ailleurs).
        $this->saveProfile(runningSinceYear: (int) $this->now->format('Y') - 1);
        self::assertGreaterThan($inferred->projectedVdot, $this->predict($goal)->projectedVdot);
    }

    private function importRun(int $id, \DateTimeImmutable $date): void
    {
        $directory = sys_get_temp_dir().'/mystrava-profile-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($directory);
        $handle = fopen($directory.'/activities.csv', 'w');
        \assert(false !== $handle);
        fputcsv($handle, StravaExportFactory::HEADER, escape: '');
        $utc = $date->setTimezone(new \DateTimeZone('UTC'))->setTime(7, 0);
        fputcsv($handle, [$id, $utc->format('M j, Y, g:i:s A'), 'Footing', 'Run', '', 3630, '10.00', 160, 30, 'false', '', 3630, 3600, 10000, 0, 140], escape: '');
        fclose($handle);

        self::getContainer()->get(ActivityImporter::class)->import($directory);
        (new Filesystem())->remove($directory);
    }

    private function analyze(): FitnessSnapshot
    {
        return self::getContainer()->get(FitnessAnalyzer::class)->analyze($this->now);
    }

    private function predict(Goal $goal): Prediction
    {
        $prediction = self::getContainer()->get(GoalPredictor::class)->predict($goal, $this->now);
        self::assertNotNull($prediction);

        return $prediction;
    }

    private function saveProfile(?int $maxHeartRate = null, ?int $restingHeartRate = null, ?int $birthYear = null, ?float $weightKg = null, ?float $targetWeightKg = null, ?int $runningSinceYear = null): void
    {
        $repository = self::getContainer()->get(AthleteProfileRepository::class);
        $profile = $repository->current();
        $profile->maxHeartRate = $maxHeartRate;
        $profile->restingHeartRate = $restingHeartRate;
        $profile->birthYear = $birthYear;
        $profile->weightKg = $weightKg;
        $profile->targetWeightKg = $targetWeightKg;
        $profile->runningSinceYear = $runningSinceYear;
        $repository->save($profile);
    }
}
