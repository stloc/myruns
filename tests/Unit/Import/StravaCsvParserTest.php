<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Enum\Sport;
use App\Import\StravaCsvParser;
use PHPUnit\Framework\TestCase;

final class StravaCsvParserTest extends TestCase
{
    public function testParsesEnglishExportUsingSiColumns(): void
    {
        $csv = <<<'CSV'
            Activity ID,Activity Date,Activity Name,Activity Type,Elapsed Time,Distance,Elapsed Time,Moving Time,Distance,Elevation Gain,Average Heart Rate
            123,"Jan 5, 2026, 7:12:34 AM",Footing,Run,2460,7.50,2460,2400,7502.3,35.0,148.2
            124,"Jan 6, 2026, 5:00:00 PM",Vélotaf,Ride,1800,12.00,1800,1750,12000.0,,
            CSV;

        $activities = iterator_to_array($this->parse($csv), false);

        self::assertCount(2, $activities);
        self::assertSame('123', $activities[0]->id);
        self::assertSame(Sport::Run, $activities[0]->sport);
        self::assertSame('2026-01-05 07:12:34', $activities[0]->startDate->format('Y-m-d H:i:s'));
        self::assertSame(7502.3, $activities[0]->distanceMeters);
        self::assertSame(2400, $activities[0]->movingTimeSeconds);
        self::assertSame(35.0, $activities[0]->elevationGain);
        self::assertSame(148.2, $activities[0]->averageHeartRate);
        self::assertSame(Sport::Ride, $activities[1]->sport);
        self::assertSame('2026-01-06 17:00:00', $activities[1]->startDate->format('Y-m-d H:i:s'));
        self::assertNull($activities[1]->elevationGain);
    }

    public function testParsesFrenchExportWithSingleDistanceInKm(): void
    {
        $csv = <<<'CSV'
            ID de l'activité,Date de l'activité,Nom de l'activité,Type d'activité,Temps écoulé,Distance,Dénivelé positif
            987,"5 janv. 2026, 07:12:34",Sortie du dimanche,Course à pied,3600,"10,5",80
            CSV;

        $activities = iterator_to_array($this->parse($csv), false);

        self::assertCount(1, $activities);
        self::assertSame(Sport::Run, $activities[0]->sport);
        self::assertSame('2026-01-05 07:12:34', $activities[0]->startDate->format('Y-m-d H:i:s'));
        self::assertSame(10500.0, $activities[0]->distanceMeters);
        self::assertSame(3600, $activities[0]->movingTimeSeconds);
    }

    public function testRejectsUnknownFormat(): void
    {
        $this->expectException(\RuntimeException::class);
        iterator_to_array($this->parse("foo,bar\n1,2\n"));
    }

    /**
     * @return iterable<\App\Entity\Activity>
     */
    private function parse(string $csv): iterable
    {
        $stream = fopen('php://memory', 'r+');
        \assert(false !== $stream);
        fwrite($stream, $csv);
        rewind($stream);

        yield from (new StravaCsvParser())->parse($stream);
    }
}
