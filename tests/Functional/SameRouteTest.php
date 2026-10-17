<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activity;
use App\Enum\Sport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Page détail : comparaison avec les autres sorties du même parcours.
 */
final class SameRouteTest extends WebTestCase
{
    /** Boucle rectangulaire de 1 km × 500 m (3 km), sens horaire. */
    private const LOOP = [[0, 0], [0, 500], [1000, 500], [1000, 0], [0, 0]];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testComparesWithOutingsOfTheSameRoute(): void
    {
        $this->add('a', '2026-09-01', 900, self::LOOP, averageHeartRate: 150);
        $this->add('b', '2026-09-08', 840, self::LOOP, offset: 20, averageHeartRate: 156);
        $this->add('c', '2026-09-15', 870, self::LOOP, offset: -15);
        // Pas le même parcours : boucle à l'envers, autre départ, autre sport.
        $this->add('reverse', '2026-09-10', 700, array_reverse(self::LOOP));
        $this->add('elsewhere', '2026-09-11', 700, self::LOOP, offset: 2000);
        $this->add('bike', '2026-09-12', 400, self::LOOP, sport: Sport::Ride);
        $this->entityManager()->flush();

        $this->client->request('GET', '/activites/c');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#same-route-title', 'Même parcours');
        self::assertSelectorTextContains('#same-route-title + .card-subtitle', '3 sorties sur ce parcours depuis le 01/09/2026');
        self::assertSelectorCount(3, '.same-route-table tbody tr');
        self::assertSelectorTextContains('.same-route-table tbody tr:first-child', 'Cette sortie');
        // 14:30 : 2ᵉ derrière b (14:00), 30 s plus lent ; moyenne des autres 14:30 ; précédente b.
        self::assertSelectorTextContains('.same-route-table .is-current', '+0:30');
        $tiles = $this->client->getCrawler()->filter('#same-route-title ~ .stat-grid .stat-tile')->each(static fn ($tile): string => preg_replace('/\s+/', ' ', trim($tile->text())) ?? '');
        self::assertSame([
            'Classement 2ᵉ sur 3 sorties',
            'Meilleur temps 14:00 le 08/09/2026 · +0:30',
            'Moyenne des autres sorties 14:30 = · FC moy. 153 bpm',
            'Sortie précédente 14:00 le 08/09/2026 · +0:30',
        ], $tiles);
    }

    public function testRecordAndNoSectionForAUniqueRoute(): void
    {
        $this->add('a', '2026-09-01', 900, self::LOOP);
        $this->add('b', '2026-09-08', 840, self::LOOP);
        $this->add('alone', '2026-09-09', 840, [[0, 0], [0, 3000]]);
        $this->entityManager()->flush();

        $this->client->request('GET', '/activites/b');
        self::assertSelectorTextContains('#same-route-title ~ .stat-grid', 'Record');
        self::assertSelectorTextContains('#same-route-title ~ .stat-grid', '−1:00');

        $this->client->request('GET', '/activites/alone');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#same-route-title');
    }

    /**
     * Course enregistrée en base avec sa trace (sommets en mètres, [est, nord] depuis 48° N, 4° O).
     *
     * @param list<array{0: int, 1: int}> $vertices
     */
    private function add(string $id, string $date, int $seconds, array $vertices, float $offset = 0.0, Sport $sport = Sport::Run, ?float $averageHeartRate = null): void
    {
        $points = [];
        $distance = 0.0;
        for ($i = 1, $count = \count($vertices); $i < $count; ++$i) {
            [$x0, $y0] = $vertices[$i - 1];
            [$x1, $y1] = $vertices[$i];
            $length = hypot($x1 - $x0, $y1 - $y0);
            for ($d = 0.0; $d < $length; $d += 20) {
                $points[] = self::point($x0 + ($x1 - $x0) * $d / $length + $offset, $y0 + ($y1 - $y0) * $d / $length + $offset, $distance + $d);
            }
            $distance += $length;
        }
        [$x, $y] = $vertices[\count($vertices) - 1];
        $points[] = self::point($x + $offset, $y + $offset, $distance);

        $activity = new Activity($id, 'track_file', 'Boucle '.$id, $sport, new \DateTimeImmutable($date.' 08:00', new \DateTimeZone('UTC')), $distance, $seconds, $seconds, averageHeartRate: $averageHeartRate);
        $activity->replaceTrackData([], null, routePoints: array_map(static fn (array $p): array => [$p[0], $p[1], $p[2], (int) round($p[2] / $distance * $seconds)], $points));
        $this->entityManager()->persist($activity);
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private static function point(float $east, float $north, float $distance): array
    {
        return [round(48 + $north / 111195, 5), round(-4 + $east / (111195 * cos(deg2rad(48))), 5), $distance];
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
