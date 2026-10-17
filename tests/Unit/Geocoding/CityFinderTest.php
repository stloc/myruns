<?php

declare(strict_types=1);

namespace App\Tests\Unit\Geocoding;

use App\Geocoding\CityFinder;
use App\Geocoding\IgnReverseGeocoder;
use App\Geocoding\NominatimReverseGeocoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class CityFinderTest extends TestCase
{
    /** @var list<string> requêtes reçues par le faux Nominatim */
    private array $nominatim = [];

    public function testIgnCityInFranceWithoutCallingNominatim(): void
    {
        $finder = $this->finder(ignCity: 'Brest');

        self::assertSame('Brest', $finder->cityAt(48.39, -4.48));
        self::assertSame([], $this->nominatim);
    }

    public function testAbroadCityComesFromNominatimAndIsCached(): void
    {
        $finder = $this->finder(ignCity: null, address: ['town' => 'Pienza', 'county' => 'Siena', 'country' => 'Italie']);

        self::assertSame('Pienza', $finder->cityAt(43.06065, 11.68115));
        self::assertSame('Pienza', $finder->cityAt(43.06071, 11.68109));
        self::assertCount(1, $this->nominatim);
        self::assertStringStartsWith('https://nominatim.openstreetmap.org/reverse?', $this->nominatim[0]);
        self::assertStringContainsString('zoom=10', $this->nominatim[0]);
        self::assertStringContainsString('accept-language=fr', $this->nominatim[0]);
    }

    public function testCityIsPreferredToTownOrVillage(): void
    {
        $finder = $this->finder(ignCity: null, address: ['village' => 'Lavapiés', 'city' => 'Madrid']);

        self::assertSame('Madrid', $finder->cityAt(40.40941, -3.69284));
    }

    public function testNoCityAtSea(): void
    {
        $finder = $this->finder(ignCity: null, address: null);

        self::assertNull($finder->cityAt(47.0, -6.0));
    }

    /**
     * @param array<string, string>|null $address adresse renvoyée par Nominatim, null pour « Unable to geocode »
     */
    private function finder(?string $ignCity, ?array $address = []): CityFinder
    {
        $ign = new MockHttpClient(static fn (): JsonMockResponse => new JsonMockResponse(['features' => null === $ignCity ? [] : [['properties' => ['city' => $ignCity]]]]), 'https://data.geopf.fr');
        $nominatim = new MockHttpClient(function (string $method, string $url) use ($address): JsonMockResponse {
            $this->nominatim[] = $url;

            return new JsonMockResponse(null === $address ? ['error' => 'Unable to geocode'] : ['address' => $address]);
        }, 'https://nominatim.openstreetmap.org');

        return new CityFinder(
            new IgnReverseGeocoder($ign, new ArrayAdapter()),
            new NominatimReverseGeocoder($nominatim, new ArrayAdapter(), minIntervalSeconds: 0.0),
        );
    }
}
