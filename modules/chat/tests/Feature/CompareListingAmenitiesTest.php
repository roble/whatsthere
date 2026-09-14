<?php

namespace Modules\Chat\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Modules\Chat\Ai\ChatAgent;
use Modules\Chat\Ai\Tools\CompareListingAmenities;
use Modules\Chat\Ai\Tools\FindPlaces;
use Modules\Chat\Ai\Tools\SavePropertyPreferences;
use Modules\Chat\Ai\Tools\UpdatePropertySearchPreferences;
use Modules\Chat\Models\OnboardingState;
use Modules\Properties\Models\Property;
use Modules\Properties\PropertyPreferences;
use Tests\TestCase;

class CompareListingAmenitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_property_chat_offers_the_compare_tool_instead_of_find_places(): void
    {
        $state = $this->propertyState();
        $names = array_map(fn ($tool) => $tool->name(), [...(new ChatAgent(null, $state))->tools()]);

        $this->assertSame([
            UpdatePropertySearchPreferences::NAME,
            CompareListingAmenities::NAME,
        ], $names);
        $instructions = (string) (new ChatAgent(null, $state))->instructions();

        $this->assertStringContainsString('compare_listing_amenities', $instructions);
        $this->assertStringContainsString('Never use find_places', $instructions);
        $this->assertStringContainsString('already euro', $instructions);
        $this->assertStringNotContainsString('"max_price"', $instructions);
    }

    public function test_it_ranks_listings_from_their_coordinates_not_a_geocoded_address(): void
    {
        $close = $this->listing('close-in-town', 51.8977, -8.4701);
        $far = $this->listing('far-rural', 51.7200, -9.0500);
        $state = $this->propertyState();

        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([[
                'lat' => '51.72',
                'lon' => '-9.05',
                'display_name' => 'Carrigboy, Kilmichael, Ireland',
                'boundingbox' => ['51.71', '51.73', '-9.06', '-9.04'],
            ]]),
            'overpass-api.de/*' => Http::response(['elements' => [
                ['type' => 'node', 'id' => 1, 'lat' => 51.8980, 'lon' => -8.4705, 'tags' => ['name' => 'CUH', 'amenity' => 'hospital']],
                ['type' => 'node', 'id' => 2, 'lat' => 51.8979, 'lon' => -8.4703, 'tags' => ['name' => 'St Marys', 'amenity' => 'school']],
                ['type' => 'node', 'id' => 3, 'lat' => 51.8978, 'lon' => -8.4702, 'tags' => ['name' => 'Stop A', 'highway' => 'bus_stop']],
                ['type' => 'node', 'id' => 4, 'lat' => 51.7210, 'lon' => -9.0490, 'tags' => ['name' => 'Dromleigh National School', 'amenity' => 'school']],
            ]]),
        ]);

        $result = json_decode((string) (new CompareListingAmenities($state))->handle(new Request([
            'categories' => ['hospital', 'school', 'bus_stop'],
        ])), true);

        $this->assertSame($close->id, $result['winner']['id']);
        $this->assertTrue($result['winner']['complete']);
        $this->assertSame('CUH', $result['rankings'][0]['nearest']['hospital']['name']);
        $this->assertSame('St Marys', $result['rankings'][0]['nearest']['school']['name']);
        $this->assertSame('Stop A', $result['rankings'][0]['nearest']['bus_stop']['name']);
        $this->assertSame($far->id, $result['rankings'][1]['id']);
        $this->assertContains('hospital', $result['rankings'][1]['missing']);
        $this->assertContains('bus_stop', $result['rankings'][1]['missing']);
        $this->assertSame('amenities', $result['categoryKey']);
        $this->assertContains($close->id, array_column($result['markers'], 'id'));
        $this->assertSame('CUH', collect($result['markers'])->firstWhere('categoryKey', 'hospital')['name']);

        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'nominatim.openstreetmap.org'));
        Http::assertSent(function (ClientRequest $request): bool {
            if (! str_contains($request->url(), 'overpass-api.de')) {
                return false;
            }

            return str_contains($request['data'], 'around:40000,51.897700,-8.470100')
                && str_contains($request['data'], '["amenity"="hospital"]')
                && str_contains($request['data'], '["amenity"="school"]')
                && str_contains($request['data'], '["highway"="bus_stop"]');
        });
    }

    public function test_it_can_restrict_the_comparison_to_named_listings(): void
    {
        $kept = $this->listing('kept', 51.8977, -8.4701);
        $this->listing('ignored', 51.8988, -8.4711);
        $state = $this->propertyState();

        Http::fake([
            'overpass-api.de/*' => Http::response(['elements' => [
                ['type' => 'node', 'id' => 1, 'lat' => 51.8978, 'lon' => -8.4702, 'tags' => ['name' => 'Stop A', 'highway' => 'bus_stop']],
            ]]),
        ]);

        $result = json_decode((string) (new CompareListingAmenities($state))->handle(new Request([
            'categories' => ['bus_stop'],
            'listing_ids' => [$kept->id],
        ])), true);

        $this->assertSame([$kept->id], array_column($result['rankings'], 'id'));
        $this->assertSame('Stop A', $result['rankings'][0]['nearest']['bus_stop']['name']);
    }

    public function test_it_answers_in_prose_when_the_map_has_no_listings(): void
    {
        $state = $this->propertyState();

        $result = (new CompareListingAmenities($state))->handle(new Request([
            'categories' => ['hospital', 'school', 'bus_stop'],
        ]));

        $this->assertStringContainsString('no listings', (string) $result);
        $this->assertNull(json_decode((string) $result, true));
        Http::assertNothingSent();
    }

    public function test_around_points_searches_from_coordinates_in_one_query(): void
    {
        Http::fake([
            'overpass-api.de/*' => Http::response(['elements' => [
                ['type' => 'node', 'id' => 1, 'lat' => 51.9, 'lon' => -8.47, 'tags' => ['name' => 'CUH', 'amenity' => 'hospital']],
            ]]),
        ]);

        $markers = (new FindPlaces)->aroundPoints(
            [['lat' => 51.8977, 'lon' => -8.4701]],
            ['hospital', 'school'],
        );

        $this->assertSame('CUH', $markers[0]['name']);
        $this->assertSame('hospital', $markers[0]['categoryKey']);
        Http::assertSentCount(1);
    }

    protected function propertyState(): OnboardingState
    {
        return OnboardingState::create([
            'conversation_id' => (string) Str::uuid(),
            'flow' => 'property',
            'phase' => 'mapping',
            'plan' => SavePropertyPreferences::plan([
                ...PropertyPreferences::defaults(),
                'location' => 'Cork',
                'location_type' => 'county',
            ]),
        ]);
    }

    protected function listing(string $reference, float $latitude, float $longitude): Property
    {
        $property = Property::create([
            'reference' => $reference,
            'address' => ucfirst($reference).' Address',
            'town' => 'Cork',
            'county' => 'Cork',
            'country' => 'IE',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'property_type' => 'house',
            'bedrooms' => 3,
            'description' => 'Test property.',
            'status' => 'for_sale',
        ]);

        $property->priceRecords()->create([
            'record_type' => 'asking_price',
            'amount' => 28500000,
            'currency' => 'EUR',
            'effective_date' => '2026-09-01',
        ]);

        return $property;
    }
}
