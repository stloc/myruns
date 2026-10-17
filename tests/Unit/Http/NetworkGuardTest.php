<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\NetworkGuard;
use PHPUnit\Framework\TestCase;

final class NetworkGuardTest extends TestCase
{
    public function testWaitsTheMinimalIntervalBetweenTwoCalls(): void
    {
        $guard = new NetworkGuard('Test', null, 0.2);
        $times = [];

        $guard->call(static function () use (&$times): void { $times[] = microtime(true); });
        $guard->call(static function () use (&$times): void { $times[] = microtime(true); });

        self::assertGreaterThanOrEqual(0.19, $times[1] - $times[0]);
    }

    public function testSuccessResetsTheFailureCount(): void
    {
        $guard = new NetworkGuard('Test', null);
        $fail = static fn (): never => throw new \RuntimeException('503');

        foreach ([$fail, $fail, static fn (): bool => true, $fail, $fail] as $call) {
            try {
                $guard->call($call);
            } catch (\RuntimeException) {
            }
        }

        $called = false;
        $guard->call(static function () use (&$called): void { $called = true; });
        self::assertTrue($called, 'Jamais 3 échecs de suite : le service est toujours appelé');
    }

    public function testStopsAfterThreeConsecutiveFailures(): void
    {
        $guard = new NetworkGuard('Test', null);
        $calls = 0;
        for ($i = 0; $i < 5; ++$i) {
            try {
                $guard->call(static function () use (&$calls): never {
                    ++$calls;

                    throw new \RuntimeException('503');
                });
            } catch (\RuntimeException) {
            }
        }

        self::assertSame(3, $calls);
        $this->expectExceptionMessage('Service Test injoignable.');
        $guard->call(static fn (): bool => true);
    }
}
