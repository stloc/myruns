<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\TrainingWeekOverride;
use PHPUnit\Framework\TestCase;

final class TrainingWeekOverrideTest extends TestCase
{
    public function testSwapsComposeOnTheDaysAsShown(): void
    {
        $override = new TrainingWeekOverride(new \DateTimeImmutable('2026-11-16'));

        $override->swapDays(2, 3);
        self::assertSame([2 => 3, 3 => 2], $override->days);

        // Le mercredi affiché (séance prévue mardi) passe au vendredi.
        $override->swapDays(3, 5);
        self::assertSame([2 => 5, 3 => 2, 5 => 3], $override->days);
        self::assertFalse($override->isEmpty());
    }

    public function testSwappingBackClearsTheAdjustment(): void
    {
        $override = new TrainingWeekOverride(new \DateTimeImmutable('2026-11-16'));
        $override->swapDays(6, 7);
        $override->swapDays(7, 6);

        self::assertNull($override->days);
        self::assertTrue($override->isEmpty());
    }

    public function testAnEditFollowsItsSessionWhenDaysAreSwapped(): void
    {
        $override = new TrainingWeekOverride(new \DateTimeImmutable('2026-11-16'));
        $override->swapDays(2, 3);
        // Séance affichée mercredi = séance prévue mardi.
        $override->editSession(3, 'interval', 'Club : pyramide', 8.0);
        self::assertSame([2 => ['type' => 'interval', 'description' => 'Club : pyramide', 'km' => 8.0]], $override->edits);

        $override->forgetEdit(3);
        self::assertNull($override->edits);
    }

    public function testResetForgetsSessionsDaysAndEdits(): void
    {
        $override = new TrainingWeekOverride(new \DateTimeImmutable('2026-11-16'));
        $override->adjust(3);
        $override->swapDays(1, 2);
        $override->editSession(4, 'hills', 'Côtes', 7.0);
        $override->reset();

        self::assertTrue($override->isEmpty());
    }
}
