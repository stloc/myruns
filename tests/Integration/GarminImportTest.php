<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\Sport;
use App\Import\ActivityImporter;
use App\Repository\ActivityRepository;
use App\Tests\Fit\FitFileBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Export de compte Garmin Connect : résumé JSON + FIT rangés dans une archive imbriquée,
 * rattachés à leur ligne par l'heure de départ.
 */
final class GarminImportTest extends KernelTestCase
{
    private const SUMMARY = 'DI_CONNECT/DI-Connect-Fitness/coureur_0_summarizedActivities.json';
    private const UPLOADS = 'DI_CONNECT/DI-Connect-Uploaded-Files/UploadedFiles_0-_Part1.zip';

    private string $workDir;
    private \DateTimeImmutable $start;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->workDir = sys_get_temp_dir().'/myruns-garmin-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->workDir);
        $this->start = new \DateTimeImmutable('2019-11-03 12:51:46', new \DateTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->workDir);
        parent::tearDown();
    }

    public function testArchiveImportAttachesNestedFitTracksAndIsIdempotent(): void
    {
        $extractedBefore = $this->extractedArchives();
        $zipPath = $this->workDir.'/garmin.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString(self::SUMMARY, $this->summary());
        $zip->addFromString(self::UPLOADS, $this->uploads());
        $zip->addFromString('DI_CONNECT/DI-Connect-User/user_profile.json', '{}');
        $zip->close();

        $importer = self::getContainer()->get(ActivityImporter::class);
        $first = $importer->import($zipPath);
        $second = $importer->import($zipPath);

        self::assertSame(2, $first->created, 'Une activité par ligne du résumé, aucune pour la trace');
        self::assertSame(1, $first->withSplits);
        self::assertSame(1, $first->ignored, 'Fichier FIT illisible ignoré');
        self::assertSame(0, $second->created);
        self::assertSame(0, $second->updated);
        self::assertSame(0, $second->withSplits);
        $this->assertImported();
        self::assertSame($extractedBefore, $this->extractedArchives(), 'Archive imbriquée effacée après l\'import');
    }

    public function testUnzippedExportFolder(): void
    {
        $folder = $this->workDir.'/export';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($folder.'/'.self::SUMMARY, $this->summary());
        $filesystem->dumpFile($folder.'/'.self::UPLOADS, $this->uploads());

        $result = self::getContainer()->get(ActivityImporter::class)->import($folder);

        self::assertSame(2, $result->created);
        self::assertSame(1, $result->withSplits);
        $this->assertImported();
    }

    private function assertImported(): void
    {
        $repository = self::getContainer()->get(ActivityRepository::class);
        self::assertSame(2, $repository->count());

        $run = $repository->find('garmin:123456789');
        self::assertNotNull($run);
        self::assertSame('Footing du dimanche', $run->name);
        self::assertSame(3000.0, $run->distanceMeters, 'Le résumé Garmin fait foi, pas la trace');
        self::assertTrue($run->hasSplits());
        self::assertSame(Sport::Hike, $repository->find('garmin:987654321')?->sport);
    }

    private function summary(): string
    {
        $start = $this->start->getTimestamp() * 1000;

        return json_encode([['summarizedActivitiesExport' => [
            ['activityId' => 123456789, 'name' => 'Footing du dimanche', 'activityType' => 'running', 'startTimeGmt' => (float) $start, 'duration' => 1200000.0, 'distance' => 300000.0, 'elevationGain' => 1500.0],
            ['activityId' => 987654321, 'activityType' => 'hiking', 'startTimeGmt' => (float) $start - 86400000, 'duration' => 3600000.0, 'distance' => 500000.0],
        ]]], \JSON_THROW_ON_ERROR);
    }

    /**
     * Archive imbriquée des FIT : celui de la course démarre 20 s après l'heure du résumé.
     */
    private function uploads(): string
    {
        $builder = (new FitFileBuilder())->session(1);
        for ($t = 0; $t <= 1150; ++$t) {
            $builder->record($this->start->getTimestamp() + 20 + $t, $t * 2.6, 150);
        }

        $path = $this->workDir.'/uploads.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('coureur_820268313.fit', $builder->build());
        $zip->addFromString('coureur_177192133.fit', 'pas un FIT');
        $zip->close();

        $contents = (string) file_get_contents($path);
        unlink($path);

        return $contents;
    }

    /**
     * @return list<string>
     */
    private function extractedArchives(): array
    {
        return glob(sys_get_temp_dir().'/myruns-*-[0-9]*.zip') ?: [];
    }
}
