<?php

namespace Modules\Chat\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Modules\Chat\Ai\Tools\ShowOnMap;
use Tests\TestCase;

class ShowOnMapTest extends TestCase
{
    public function test_it_returns_a_view_the_map_embed_can_use(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([[
                'lat' => '51.8985143',
                'lon' => '-8.4756035',
                'display_name' => 'Cork, County Cork, Ireland',
                // Nominatim orders this south, north, west, east.
                'boundingbox' => ['51.8574000', '51.9295000', '-8.5340000', '-8.3960000'],
            ]]),
        ]);

        $result = (new ShowOnMap)->handle(new Request(['place' => 'Cork']));

        $this->assertSame([
            'label' => 'Cork, County Cork, Ireland',
            // The map wants west, south, east, north. Getting this order
            // wrong still renders a map, just of the wrong part of the world.
            'bbox' => ['-8.5340000', '51.8574000', '-8.3960000', '51.9295000'],
            'marker' => ['51.8985143', '-8.4756035'],
        ], json_decode((string) $result, true));
    }

    public function test_it_only_ever_asks_the_geocoder_for_irish_places(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

        (new ShowOnMap)->handle(new Request(['place' => 'Paris, France']));

        // The instructions ask the model to stay in Ireland; this is the half
        // of the scope it cannot talk its way around.
        Http::assertSent(fn ($request) => $request['countrycodes'] === 'ie');
    }

    public function test_it_only_geocodes_a_repeated_place_once(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([[
                'lat' => '51.7057370',
                'lon' => '-8.5229823',
                'display_name' => 'Kinsale, County Cork, Ireland',
                'boundingbox' => ['51.6927609', '51.7157766', '-8.5424283', '-8.4897026'],
            ]]),
        ]);

        $first = (new ShowOnMap)->handle(new Request(['place' => 'Kinsale']));
        $second = (new ShowOnMap)->handle(new Request(['place' => 'kinsale']));

        $this->assertSame((string) $first, (string) $second);
        Http::assertSentCount(1);
    }

    public function test_a_failed_lookup_is_not_cached(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::sequence()
                ->push('', 503)
                ->push([[
                    'lat' => '51.7057370',
                    'lon' => '-8.5229823',
                    'display_name' => 'Kinsale, County Cork, Ireland',
                    'boundingbox' => ['51.6927609', '51.7157766', '-8.5424283', '-8.4897026'],
                ]]),
        ]);

        $failed = (string) (new ShowOnMap)->handle(new Request(['place' => 'Kinsale']));
        $retried = (string) (new ShowOnMap)->handle(new Request(['place' => 'Kinsale']));

        $this->assertStringContainsString('Could not find', $failed);
        $this->assertSame(
            'Kinsale, County Cork, Ireland',
            json_decode($retried, true)['label'],
        );
    }

    public function test_it_leaves_the_map_alone_when_the_place_is_not_found(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([]),
        ]);

        $result = (string) (new ShowOnMap)->handle(new Request(['place' => 'Nowhere at all']));

        $this->assertStringContainsString('Could not find', $result);
        $this->assertNull(json_decode($result, true));
    }

    public function test_it_leaves_the_map_alone_when_geocoding_fails(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response('', 503),
        ]);

        $result = (string) (new ShowOnMap)->handle(new Request(['place' => 'Kinsale']));

        $this->assertStringContainsString('Could not find', $result);
    }

    public function test_it_names_the_place_at_a_point_once(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/reverse*' => Http::response([
                'display_name' => 'Douglas, Cork, County Cork, Ireland',
            ]),
        ]);

        $tool = new ShowOnMap;

        // Metres apart, so both round to the same cached square.
        $first = $tool->placeAt(51.7921, -8.4234);
        $second = $tool->placeAt(51.7923, -8.4231);

        $this->assertSame('Douglas, Cork, County Cork, Ireland', $first);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    public function test_it_names_no_place_when_the_reverse_lookup_fails(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/reverse*' => Http::response('', 503),
        ]);

        $this->assertNull((new ShowOnMap)->placeAt(51.7921, -8.4234));
    }

    public function test_it_does_not_call_the_geocoder_without_a_place(): void
    {
        Http::fake();

        $result = (string) (new ShowOnMap)->handle(new Request(['place' => '  ']));

        $this->assertStringContainsString('No place was given', $result);
        Http::assertNothingSent();
    }
}
