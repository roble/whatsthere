<?php

namespace Modules\Chat\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Modules\Chat\Ai\Tools\FindPlaces;
use Modules\Chat\Ai\Tools\ShowOnMap;
use Tests\TestCase;

class FindPlacesTest extends TestCase
{
    /**
     * Fake the geocoder with a Galway-shaped box, and Overpass with whatever
     * elements the test is about.
     *
     * @param  array<int, array<string, mixed>>  $elements
     */
    protected function fakeServices(array $elements = []): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([[
                'lat' => '53.2707',
                'lon' => '-9.0568',
                'display_name' => 'Galway, Ireland',
                'boundingbox' => ['53.2500', '53.3000', '-9.1000', '-9.0000'],
            ]]),
            'overpass-api.de/*' => Http::response(['elements' => $elements]),
        ]);
    }

    public function test_it_puts_every_result_on_the_map(): void
    {
        $this->fakeServices([
            ['type' => 'node', 'lat' => 53.2741, 'lon' => -9.0476, 'tags' => ['name' => "Darcy's Bar"]],
            ['type' => 'node', 'lat' => 53.2745, 'lon' => -9.0480, 'tags' => ['name' => 'The Skeff']],
        ]);

        $result = (new FindPlaces)->handle(new Request(['category' => 'pub', 'area' => 'Galway']));

        $this->assertSame([
            'label' => 'Pubs in Galway, Ireland',
            'categoryKey' => 'pub',
            'category' => 'pubs',
            'bbox' => ['-9.1000', '53.2500', '-9.0000', '53.3000'],
            'markers' => [
                ['lat' => 53.2741, 'lon' => -9.0476, 'name' => "Darcy's Bar"],
                ['lat' => 53.2745, 'lon' => -9.0480, 'name' => 'The Skeff'],
            ],
        ], array_diff_key(json_decode((string) $result, true), ['note' => true]));
    }

    public function test_it_carries_useful_tags_as_details_and_prefers_named_places(): void
    {
        $this->fakeServices([
            ['type' => 'node', 'lat' => 53.1, 'lon' => -9.1, 'tags' => ['amenity' => 'cafe']],
            ['type' => 'node', 'lat' => 53.2, 'lon' => -9.2, 'tags' => [
                'name' => 'Coffeewerk',
                'name:en' => 'Coffeewerk + Press',
                'opening_hours' => 'Mo-Su 08:00-18:00',
                'addr:housenumber' => '4',
                'addr:street' => 'Quay Street',
                'addr:city' => 'Galway',
                'website' => 'https://coffeewerk.example',
                'wheelchair' => 'yes',
                'internet_access' => 'wlan',
                'brand:wikidata' => 'Q1',
            ]],
        ]);

        $markers = json_decode((string) (new FindPlaces)->handle(new Request(['category' => 'cafe', 'area' => 'Galway'])), true)['markers'];

        $this->assertSame('Coffeewerk + Press', $markers[0]['name']);
        $this->assertSame([
            'address' => '4 Quay Street, Galway',
            'hours' => 'Mo-Su 08:00-18:00',
            'website' => 'https://coffeewerk.example',
            'wheelchair' => 'yes',
            'internet_access' => 'wlan',
        ], $markers[0]['details']);
        $this->assertSame('Cafe 1', $markers[1]['name']);
        $this->assertArrayNotHasKey('details', $markers[1]);
    }

    public function test_it_reads_the_centre_of_a_building_not_just_a_point(): void
    {
        // Ways and relations carry no top-level lat/lon -- their coordinates
        // arrive under `center`, because the query asks for `out center`.
        // Reading only the top-level pair drops every castle, hotel and
        // supermarket on the map, silently.
        $this->fakeServices([
            ['type' => 'way', 'center' => ['lat' => 52.2567, 'lon' => -9.6600], 'tags' => ['name' => 'Ballyseede Castle']],
        ]);

        $result = (new FindPlaces)->handle(new Request(['category' => 'castle', 'area' => 'Kerry']));

        $this->assertSame(
            [['lat' => 52.2567, 'lon' => -9.66, 'name' => 'Ballyseede Castle']],
            json_decode((string) $result, true)['markers'],
        );
    }

    public function test_it_names_an_unnamed_result_after_what_it_is(): void
    {
        $this->fakeServices([
            ['type' => 'node', 'lat' => 53.2741, 'lon' => -9.0476, 'tags' => ['amenity' => 'pub']],
        ]);

        $result = (new FindPlaces)->handle(new Request(['category' => 'pub', 'area' => 'Galway']));

        $this->assertSame('Pub 1', json_decode((string) $result, true)['markers'][0]['name']);
    }

    public function test_it_searches_only_inside_the_area_it_was_given(): void
    {
        $this->fakeServices();

        (new FindPlaces)->handle(new Request(['category' => 'pub', 'area' => 'Galway']));

        Http::assertSent(function (ClientRequest $request): bool {
            if (! str_contains($request->url(), 'overpass-api.de')) {
                return true;
            }

            // Overpass orders a bounding box south, west, north, east. Getting
            // it wrong still returns results, just of somewhere else.
            return str_contains($request['data'], '["amenity"="pub"]')
                && str_contains($request['data'], '(53.250000,-9.100000,53.300000,-9.000000)');
        });
    }

    public function test_it_returns_everything_the_search_asked_for(): void
    {
        $this->fakeServices(array_map(fn (int $i): array => [
            'type' => 'node', 'lat' => 53.27 + $i / 10000, 'lon' => -9.04, 'tags' => ['name' => "Pub {$i}"],
        ], range(1, 40)));

        $result = json_decode((string) (new FindPlaces)->handle(
            new Request(['category' => 'pub', 'area' => 'Galway'])
        ), true);

        // Nothing is thrown away after the fact: the query is the only cap, so
        // everything Overpass sends back reaches the map and the model.
        $this->assertCount(40, $result['markers']);

        Http::assertSent(fn (ClientRequest $request): bool => ! str_contains($request->url(), 'overpass-api.de')
            || str_contains($request['data'], 'out center 40;'));
    }

    public function test_it_ranks_the_places_openstreetmap_knows_most_about_first(): void
    {
        $this->fakeServices([
            ['type' => 'node', 'lat' => 53.271, 'lon' => -9.041, 'tags' => ['name' => 'Bare Bar']],
            ['type' => 'node', 'lat' => 53.272, 'lon' => -9.042, 'tags' => ['amenity' => 'pub']],
            ['type' => 'node', 'lat' => 53.273, 'lon' => -9.043, 'tags' => [
                'name' => 'The Skeff',
                'opening_hours' => 'Mo-Su 10:00-23:00',
                'website' => 'https://example.test',
                'wheelchair' => 'yes',
            ]],
        ]);

        $result = json_decode((string) (new FindPlaces)->handle(
            new Request(['category' => 'pub', 'area' => 'Galway'])
        ), true);

        $this->assertSame(
            ['The Skeff', 'Bare Bar', 'Pub 1'],
            array_column($result['markers'], 'name'),
        );
    }

    public function test_it_tells_unnamed_places_apart(): void
    {
        // Seventeen pins all called "Viewpoint" leave the visitor unable to
        // pick one and the model with no string that identifies one rather
        // than another -- asked for an itinerary it then reaches for the name
        // of the search itself and every stop lands on one point.
        $this->fakeServices([
            ['type' => 'node', 'lat' => 53.271, 'lon' => -9.041, 'tags' => ['amenity' => 'pub']],
            ['type' => 'node', 'lat' => 53.272, 'lon' => -9.042, 'tags' => ['amenity' => 'pub']],
            ['type' => 'node', 'lat' => 53.273, 'lon' => -9.043, 'tags' => [
                'amenity' => 'pub',
                'addr:housenumber' => '17',
                'addr:street' => 'Quay Street',
            ]],
            ['type' => 'node', 'lat' => 53.274, 'lon' => -9.044, 'tags' => [
                'amenity' => 'pub',
                'addr:housenumber' => '17',
                'addr:street' => 'Quay Street',
            ]],
        ]);

        $result = json_decode((string) (new FindPlaces)->handle(
            new Request(['category' => 'pub', 'area' => 'Galway'])
        ), true);

        $this->assertSame(
            ['Pub at 17 Quay Street #3', 'Pub at 17 Quay Street #4', 'Pub 1', 'Pub 2'],
            array_column($result['markers'], 'name'),
        );
    }

    public function test_it_refuses_a_category_it_does_not_know(): void
    {
        $this->fakeServices();

        $result = (new FindPlaces)->handle(new Request(['category' => 'nightclub', 'area' => 'Galway']));

        $this->assertStringContainsString('no category called [nightclub]', (string) $result);

        // The guard runs before anything is called, so a bad category costs
        // neither a geocode nor an Overpass query.
        Http::assertNothingSent();
    }

    public function test_it_answers_in_prose_when_the_area_cannot_be_placed(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

        $result = (new FindPlaces)->handle(new Request(['category' => 'pub', 'area' => 'Narnia']));

        $this->assertStringContainsString('Could not find [Narnia]', (string) $result);
        $this->assertNull(json_decode((string) $result, true));
    }

    public function test_it_answers_in_prose_when_the_area_holds_nothing(): void
    {
        $this->fakeServices();

        $result = (new FindPlaces)->handle(new Request(['category' => 'castle', 'area' => 'Galway']));

        $this->assertStringContainsString('Found no castles', (string) $result);
        $this->assertNull(json_decode((string) $result, true));
    }

    public function test_it_moves_on_to_another_instance_when_the_first_sheds_load(): void
    {
        // The main instance is the busiest thing in OpenStreetMap and answers
        // 504 from its gateway when it is loaded. Measured from here that was
        // two requests in three, which is what "could not load what is nearby"
        // actually was. The mirrors hold the same planet.
        config(['chat.overpass_endpoints' => [
            'https://overpass-api.de/api/interpreter',
            'https://overpass.kumi.systems/api/interpreter',
        ]]);

        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([[
                'lat' => '53.2707', 'lon' => '-9.0568',
                'display_name' => 'Galway, Ireland',
                'boundingbox' => ['53.2500', '53.3000', '-9.1000', '-9.0000'],
            ]]),
            'overpass-api.de/*' => Http::response(null, 504),
            'overpass.kumi.systems/*' => Http::response(['elements' => [
                ['type' => 'node', 'lat' => 53.2741, 'lon' => -9.0476, 'tags' => ['name' => "Darcy's Bar"]],
            ]]),
        ]);

        $result = (new FindPlaces)->handle(new Request(['category' => 'pub', 'area' => 'Galway']));

        $this->assertSame("Darcy's Bar", json_decode((string) $result, true)['markers'][0]['name']);
    }

    public function test_a_refused_connection_is_tried_elsewhere_rather_than_thrown(): void
    {
        config(['chat.overpass_endpoints' => [
            'https://overpass-api.de/api/interpreter',
            'https://overpass.kumi.systems/api/interpreter',
        ]]);

        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([[
                'lat' => '53.2707', 'lon' => '-9.0568',
                'display_name' => 'Galway, Ireland',
                'boundingbox' => ['53.2500', '53.3000', '-9.1000', '-9.0000'],
            ]]),
            // An instance that hangs raises rather than returning a response,
            // so the loop has to catch it or the whole reply dies on the one
            // server that happened to be unreachable.
            'overpass-api.de/*' => fn () => throw new ConnectionException('timed out'),
            'overpass.kumi.systems/*' => Http::response(['elements' => [
                ['type' => 'node', 'lat' => 53.2741, 'lon' => -9.0476, 'tags' => ['name' => "Darcy's Bar"]],
            ]]),
        ]);

        $result = (new FindPlaces)->handle(new Request(['category' => 'pub', 'area' => 'Galway']));

        $this->assertSame("Darcy's Bar", json_decode((string) $result, true)['markers'][0]['name']);
    }

    public function test_a_two_hundred_carrying_no_results_is_not_an_answer(): void
    {
        // Overpass reports a server-side query timeout as a 200 with a
        // `remark` and no `elements`, and a proxy in front of it will hand back
        // an HTML error page just as cheerfully. Read as an empty list, either
        // would be cached as "there is nothing here" for a month and would stop
        // the failover before it reached an instance that had the answer.
        config(['chat.overpass_endpoints' => [
            'https://overpass-api.de/api/interpreter',
            'https://overpass.kumi.systems/api/interpreter',
        ]]);

        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([[
                'lat' => '53.2707', 'lon' => '-9.0568',
                'display_name' => 'Galway, Ireland',
                'boundingbox' => ['53.2500', '53.3000', '-9.1000', '-9.0000'],
            ]]),
            'overpass-api.de/*' => Http::response(['remark' => 'runtime error: Query timed out']),
            'overpass.kumi.systems/*' => Http::response(['elements' => [
                ['type' => 'node', 'lat' => 53.2741, 'lon' => -9.0476, 'tags' => ['name' => "Darcy's Bar"]],
            ]]),
        ]);

        $result = (new FindPlaces)->handle(new Request(['category' => 'pub', 'area' => 'Galway']));

        $this->assertSame("Darcy's Bar", json_decode((string) $result, true)['markers'][0]['name']);
    }

    public function test_a_forced_warm_asks_again_rather_than_reading_the_cache(): void
    {
        config(['chat.overpass_endpoints' => ['https://overpass-api.de/api/interpreter']]);

        Http::fake(['overpass-api.de/*' => Http::sequence()
            ->push(['elements' => [
                ['type' => 'node', 'lat' => 53.2741, 'lon' => -9.0476, 'tags' => ['name' => 'The Old Pub', 'amenity' => 'pub']],
            ]])
            ->push(['elements' => [
                ['type' => 'node', 'lat' => 53.2741, 'lon' => -9.0476, 'tags' => ['name' => 'The New Pub', 'amenity' => 'pub']],
            ]]),
        ]);

        $places = new FindPlaces;
        $places->aroundMany(53.27, -9.05, ['pub']);

        // Without this the forced run is answered from the cache, and the
        // command reports having re-asked about everything while asking about
        // nothing.
        $fresh = $places->aroundMany(53.27, -9.05, ['pub'], fresh: true);

        $this->assertSame('The New Pub', $fresh[0]['name']);
        Http::assertSentCount(2);
    }

    public function test_an_outage_is_not_cached_as_an_empty_area(): void
    {
        config(['chat.overpass_endpoints' => ['https://overpass-api.de/api/interpreter']]);

        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([[
                'lat' => '53.2707', 'lon' => '-9.0568',
                'display_name' => 'Galway, Ireland',
                'boundingbox' => ['53.2500', '53.3000', '-9.1000', '-9.0000'],
            ]]),
            // Down, then back up. A second Http::fake() would not express this:
            // the stubs merge and the first match keeps winning.
            'overpass-api.de/*' => Http::sequence()
                ->push(null, 504)
                ->push(['elements' => [
                    ['type' => 'node', 'lat' => 53.2741, 'lon' => -9.0476, 'tags' => ['name' => "Darcy's Bar"]],
                ]]),
        ]);

        $tool = new FindPlaces;

        $this->assertStringContainsString(
            'could not be reached',
            (string) $tool->handle(new Request(['category' => 'pub', 'area' => 'Galway'])),
        );

        // An outage must not pin the area to "nothing here" for a month. Had
        // the failure been cached, this would never reach Overpass again and
        // would answer "found no pubs" instead.
        $retried = $tool->handle(new Request(['category' => 'pub', 'area' => 'Galway']));

        $this->assertSame("Darcy's Bar", json_decode((string) $retried, true)['markers'][0]['name']);
    }

    public function test_a_search_is_asked_of_overpass_once_and_kept_for_the_configured_month(): void
    {
        config(['chat.osm_cache_days' => 30]);
        $this->fakeServices([
            ['type' => 'node', 'lat' => 53.2741, 'lon' => -9.0476, 'tags' => ['name' => "Darcy's Bar"]],
        ]);

        $tool = new FindPlaces;
        $tool->handle(new Request(['category' => 'pub', 'area' => 'Galway']));
        $tool->handle(new Request(['category' => 'pub', 'area' => 'Galway']));

        // Overpass and Nominatim are donated infrastructure, and Overpass
        // throttles hard when leaned on. Asking the same question twice is the
        // thing this cache exists to stop.
        Http::assertSentCount(2);

        $this->assertEqualsWithDelta(
            now()->addDays(30)->getTimestamp(),
            ShowOnMap::cacheFor()->getTimestamp(),
            5,
        );
    }
}
