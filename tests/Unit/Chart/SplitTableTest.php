<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chart;

use App\Chart\SplitTable;
use App\Entity\Activity;
use App\Entity\ActivitySplit;
use App\Enum\SplitKind;
use App\Enum\Sport;
use PHPUnit\Framework\TestCase;

final class SplitTableTest extends TestCase
{
    public function testFlagsIntervalKilometersAndCumulatesTime(): void
    {
        // Séance type 3 × 1 km : 4 km à ~5:40, 3 km à ~4:30, retour au calme.
        $table = SplitTable::fromSplits($this->splits([358, 340, 336, 330, 260, 279, 274, 306], lastDistance: 957));

        $fast = array_keys(array_filter($table->rows, static fn (array $row): bool => $row['fast']));
        self::assertSame([4, 5, 6], $fast);
        self::assertSame(100, $table->rows[4]['speed_percent']);
        self::assertSame(358.0, $table->rows[0]['cumulative']);
        self::assertSame(2483.0, $table->rows[7]['cumulative']);
    }

    public function testShortLastSplitDoesNotDistortTheScale(): void
    {
        $table = SplitTable::fromSplits($this->splits([300, 301, 302, 36], lastDistance: 128));

        self::assertFalse($table->rows[3]['fast']);
        self::assertSame(100, $table->rows[3]['speed_percent']);
        self::assertSame(100, $table->rows[0]['speed_percent']);
    }

    public function testDetectsAutomaticKilometerLaps(): void
    {
        self::assertTrue(SplitTable::areAutomaticKilometers($this->splits([300, 300, 150], lastDistance: 500, kind: SplitKind::Lap)));
        self::assertFalse(SplitTable::areAutomaticKilometers([
            ...$this->splits([120], lastDistance: 400, kind: SplitKind::Lap),
            ...$this->splits([90], lastDistance: 200, kind: SplitKind::Lap),
        ]));
    }

    /**
     * @param list<int> $seconds
     *
     * @return list<ActivitySplit>
     */
    private function splits(array $seconds, float $lastDistance, SplitKind $kind = SplitKind::Kilometer): array
    {
        $activity = new Activity('a', 'strava_csv', '', Sport::Run, new \DateTimeImmutable(), 8000, 2500, 2500);
        $splits = [];
        foreach ($seconds as $i => $duration) {
            $distance = $i === \count($seconds) - 1 ? $lastDistance : 1000.0;
            $splits[] = new ActivitySplit($activity, $kind, $i + 1, $distance, $duration);
        }

        return $splits;
    }
}
