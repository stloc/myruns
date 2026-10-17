<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Enum\ActivitySort;
use App\Enum\Sport;
use App\Repository\ActivitySearch;
use PHPUnit\Framework\TestCase;

final class ActivitySearchTest extends TestCase
{
    public function testReadsCriteriaAndRebuildsTheQuery(): void
    {
        $query = ['q' => '  cani   trail ', 'sport' => 'course', 'du' => '2026-01-01', 'au' => '2026-01-31', 'km_min' => '10,5', 'km_max' => '21', 'dplus_min' => '200', 'tri' => 'allure'];

        $search = ActivitySearch::fromQuery($query);

        self::assertSame(['cani', 'trail'], $search->words);
        self::assertTrue($search->allRuns);
        self::assertNull($search->sport);
        self::assertSame('2026-01-01 00:00:00 +01:00', $search->from?->format('Y-m-d H:i:s P'));
        self::assertSame('2026-02-01 00:00:00 +01:00', $search->to?->format('Y-m-d H:i:s P'), 'Journée de fin incluse');
        self::assertSame(10.5, $search->minKm);
        self::assertSame(ActivitySort::Pace, $search->sort);
        self::assertTrue($search->isFiltered());
        self::assertSame(['q' => 'cani trail', 'sport' => 'course', 'du' => '2026-01-01', 'au' => '2026-01-31', 'km_min' => '10.5', 'km_max' => '21', 'dplus_min' => '200', 'tri' => 'allure'], $search->toQuery());
    }

    public function testIgnoresInvalidValues(): void
    {
        $search = ActivitySearch::fromQuery(['sport' => 'curling', 'du' => '2026-02-31', 'km_min' => 'dix', 'dplus_min' => '-5', 'tri' => 'hasard', 'q' => ['tableau']]);

        self::assertFalse($search->isFiltered());
        self::assertSame(ActivitySort::Date, $search->sort);
        self::assertSame([], $search->toQuery());
    }

    public function testSortDirectionDefaultsToTheUsualOrderOfTheCriterion(): void
    {
        self::assertSame(\SortDirection::Descending, ActivitySearch::fromQuery([])->direction, 'Plus récentes d\'abord');
        self::assertSame(\SortDirection::Ascending, ActivitySearch::fromQuery(['tri' => 'allure', 'ordre' => 'n-importe'])->direction, 'Plus rapides d\'abord');

        $search = ActivitySearch::fromQuery(['tri' => 'allure', 'ordre' => 'desc']);
        self::assertSame(\SortDirection::Descending, $search->direction);
        self::assertSame(['tri' => 'allure', 'ordre' => 'desc'], $search->toQuery());

        self::assertSame(['ordre' => 'asc'], ActivitySearch::fromQuery(['ordre' => 'asc'])->toQuery());
        self::assertSame([], ActivitySearch::fromQuery(['ordre' => 'desc'])->toQuery(), 'Sens habituel : omis de l\'adresse');
    }

    public function testTrailIsRecognisedByName(): void
    {
        self::assertSame(Sport::TrailRun, Sport::Run->refinedByName('Trail le matin'));
        self::assertSame(Sport::TrailRun, Sport::Run->refinedByName('Canitrail du dimanche'));
        self::assertSame(Sport::Run, Sport::Run->refinedByName('Course à pied le soir'));
        self::assertSame(Sport::Ride, Sport::Ride->refinedByName('VTT trail'), 'Seule la course devient du trail');
    }
}
