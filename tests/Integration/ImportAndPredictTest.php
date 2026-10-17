<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ai\AiCoach;
use App\Ai\CoachAnalysis;
use App\Import\ActivityImporter;
use App\Prediction\Goal;
use App\Prediction\GoalPredictor;
use App\Prediction\RunningExperience;
use App\Repository\ActivityRepository;
use App\Repository\AthleteProfileRepository;
use App\Repository\RaceResultRepository;
use App\Tests\StravaExportFactory;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Test\InMemoryPlatform;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ImportAndPredictTest extends KernelTestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->workDir = sys_get_temp_dir().'/myruns-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->workDir);
        parent::tearDown();
    }

    public function testZipImportIsIdempotentAndFeedsThePredictor(): void
    {
        $csv = StravaExportFactory::create($this->workDir.'/export');
        $zipPath = $this->workDir.'/export_42.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFile($csv, 'activities.csv');
        $zip->addFromString('activities/1001.fit.gz', 'binaire');
        $zip->close();

        $importer = self::getContainer()->get(ActivityImporter::class);
        $first = $importer->import($zipPath);
        $second = $importer->import($zipPath);

        self::assertSame(36, $first->created);
        self::assertSame(1, $first->ignored, 'Les fichiers FIT sont ignorés');
        self::assertSame(0, $second->created);
        self::assertSame(36, $second->unchanged);
        self::assertSame(36, self::getContainer()->get(ActivityRepository::class)->count());

        $predictor = self::getContainer()->get(GoalPredictor::class);
        $now = new \DateTimeImmutable();

        $easy = $predictor->predict(new Goal(10000, 50 * 60, $now->modify('+8 weeks')), $now);
        $hard = $predictor->predict(new Goal(10000, 38 * 60, $now->modify('+8 weeks')), $now);

        self::assertNotNull($easy);
        self::assertNotNull($hard);
        self::assertGreaterThan(90, $easy->probabilityPercent);
        self::assertLessThan(10, $hard->probabilityPercent);
        self::assertEqualsWithDelta(44, (float) $easy->fitness->vdot, 2.5);
    }

    public function testAiCoachSendsTheTrainingContextAndReturnsStructuredAnalysis(): void
    {
        $importer = self::getContainer()->get(ActivityImporter::class);
        $importer->import(\dirname(StravaExportFactory::create($this->workDir.'/export')));

        $captured = null;
        $platform = new InMemoryPlatform(static function (Model $model, MessageBag $input, array $options) use (&$captured): ObjectResult {
            $captured = ['model' => $model->getName(), 'input' => $input, 'options' => $options];
            $analysis = new CoachAnalysis();
            $analysis->probabilityPercent = 61;
            $analysis->predictedTime = '44:10';
            $analysis->verdict = 'Objectif ambitieux mais réaliste.';

            return new ObjectResult($analysis);
        });

        $prediction = self::getContainer()->get(GoalPredictor::class)->predict(new Goal(10000, 45 * 60, new \DateTimeImmutable('+10 weeks')));
        self::assertNotNull($prediction);

        $container = self::getContainer();
        $coach = new AiCoach($platform, $container->get(ActivityRepository::class), $container->get(AthleteProfileRepository::class), $container->get(RaceResultRepository::class), $container->get(RunningExperience::class), 'claude-sonnet-5');
        $analysis = $coach->analyze($prediction);

        self::assertSame(61, $analysis->probabilityPercent);
        self::assertIsArray($captured);
        self::assertSame('claude-sonnet-5', $captured['model']);
        self::assertSame(CoachAnalysis::class, $captured['options']['response_format']);
        $userMessage = (string) $captured['input']->getUserMessage()?->asText();
        self::assertStringContainsString('vdot_requis', $userMessage);
        self::assertStringContainsString('Séance seuil', $userMessage);
    }
}
