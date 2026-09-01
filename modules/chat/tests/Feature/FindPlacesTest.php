<?php

namespace Modules\Chat\Tests\Feature;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Modules\Chat\Ai\Tools\FindPlaces;
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
            'category' => 'pubs',
            'bbox' => ['-9.1000', '53.2500', '-9.0000', '53.3000'],
            'markers' => [
                ['lat' => 53.2741, 'lon' => -9.0476, 'name' => "Darcy's Bar"],
                ['lat' => 53.2745, 'lon' => -9.0480, 'name' => 'The Skeff'],
            ],
        ], json_decode((string) $result, true));
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

        $this->assertSame('Pub', json_decode((string) $result, true)['markers'][0]['name']);
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

    public function test_it_says_when_there_were_more_than_it_showed(): void
    {
        // 41 back for a cap of 40: the tool asks Overpass for one more than it
        // means to keep, which is the only way to tell a full house from a
        // coincidence.
        $this->fakeServices(array_map(fn (int $i): array => [
            'type' => 'node', 'lat' => 53.27 + $i / 10000, 'lon' => -9.04, 'tags' => ['name' => "Pub {$i}"],
        ], range(1, 41)));

        $result = json_decode((string) (new FindPlaces)->handle(
            new Request(['category' => 'pub', 'area' => 'Galway'])
        ), true);

        $this->assertCount(40, $result['markers']);
        $this->assertTrue($result['capped']);
    }

    public function test_an_exact_result_is_not_marked_capped(): void
    {
        $this->fakeServices([
            ['type' => 'node', 'lat' => 53.2741, 'lon' => -9.0476, 'tags' => ['name' => "Darcy's Bar"]],
        ]);

        $result = json_decode((string) (new FindPlaces)->handle(
            new Request(['category' => 'pub', 'area' => 'Galway'])
        ), true);

        // Absent rather than false: the assistant is told about a cap only
        // when there is one to tell it about.
        $this->assertArrayNotHasKey('capped', $result);
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

    public function test_an_outage_is_not_cached_as_an_empty_area(): void
    {
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

        // A timeout must not pin the area to "nothing here" for the rest of the
        // day. Had the failure been cached, this would never reach Overpass
        // again and would answer "found no pubs" instead.
        $retried = $tool->handle(new Request(['category' => 'pub', 'area' => 'Galway']));

        $this->assertSame("Darcy's Bar", json_decode((string) $retried, true)['markers'][0]['name']);
    }
}
