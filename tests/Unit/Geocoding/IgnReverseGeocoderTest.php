<?php

declare(strict_types=1);

namespace App\Tests\Unit\Geocoding;

use App\Geocoding\IgnReverseGeocoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IgnReverseGeocoderTest extends TestCase
{
    public function testCityOfTheNearestParcelIsCachedByRoundedPosition(): void
    {
        $urls = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$urls): JsonMockResponse {
            $urls[] = $url;

            return new JsonMockResponse(['type' => 'FeatureCollection', 'features' => [['properties' => ['city' => 'Brest', 'distance' => 40, '_type' => 'parcel']]]]);
        }, 'https://data.geopf.fr');
        $geocoder = new IgnReverseGeocoder($client, new ArrayAdapter());

        self::assertSame('Brest', $geocoder->cityAt(48.39041, -4.48612));
        // Même départ à quelques mètres près : servi par le cache.
        self::assertSame('Brest', $geocoder->cityAt(48.39039, -4.48608));
        self::assertCount(1, $urls);
        self::assertStringContainsString('/geocodage/reverse?', $urls[0]);
        self::assertStringContainsString('index=parcel', $urls[0]);
        self::assertStringContainsString('lat=48.390', $urls[0]);
    }

    public function testNoCityAbroadIsCached(): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): JsonMockResponse {
            ++$calls;

            return new JsonMockResponse(['type' => 'FeatureCollection', 'features' => []]);
        });
        $geocoder = new IgnReverseGeocoder($client, new ArrayAdapter());

        self::assertNull($geocoder->cityAt(40.41, -3.70));
        self::assertNull($geocoder->cityAt(40.41, -3.70));
        self::assertSame(1, $calls);
    }

    public function testUnavailableServiceIsRetriedLater(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 503]),
            new JsonMockResponse(['features' => [['properties' => ['city' => 'Morlaix']]]]),
        ]);
        $geocoder = new IgnReverseGeocoder($client, new ArrayAdapter());

        self::assertNull($geocoder->cityAt(48.58, -3.83));
        self::assertSame('Morlaix', $geocoder->cityAt(48.58, -3.83));
    }

    public function testServiceDownIsNoLongerCalledAfterThreeFailures(): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 503]);
        });
        $geocoder = new IgnReverseGeocoder($client, new ArrayAdapter());

        // Positions différentes (pas de cache) : sans arrêt, chaque trace attendrait le délai réseau.
        for ($i = 0; $i < 10; ++$i) {
            self::assertNull($geocoder->cityAt(48.0 + $i / 10, -4.0));
        }
        self::assertSame(3, $calls);
    }
}
