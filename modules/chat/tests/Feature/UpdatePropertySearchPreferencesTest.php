<?php

namespace Modules\Chat\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Modules\Chat\Ai\Tools\SavePropertyPreferences;
use Modules\Chat\Ai\Tools\UpdatePropertySearchPreferences;
use Modules\Chat\Models\OnboardingState;
use Modules\Properties\Models\Property;
use Modules\Properties\PropertyPreferences;
use Tests\TestCase;

class UpdatePropertySearchPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_broader_follow_up_widens_when_leftover_filters_match_nothing(): void
    {
        $house = $this->listing('cheap-house', 51.9, -8.47, 15000000, 'house');
        $state = $this->state([
            'location' => 'Cork',
            'location_type' => 'town',
            'county' => 'Cork',
            'max_price' => 35000000,
            'min_bedrooms' => 1,
            'property_type' => 'apartment',
            'minimum_ber_rating' => null,
            'sort' => 'price',
        ]);

        $result = json_decode((string) (new UpdatePropertySearchPreferences($state))->handle(new Request([
            'max_price' => 200000,
        ])), true);

        $this->assertSame(1, $result['total']);
        $this->assertSame($house->id, $result['markers'][0]['id']);
        $this->assertContains('property_type', $result['relaxed']);
        $this->assertSame(20000000, $state->fresh()->plan['preferences']['max_price']);
        $this->assertNull($state->fresh()->plan['preferences']['property_type']);
    }

    public function test_replace_clears_leftover_type_and_bedrooms(): void
    {
        $house = $this->listing('any-home', 51.9, -8.47, 18000000, 'house');
        $state = $this->state([
            ...PropertyPreferences::defaults(),
            'location' => 'Cork',
            'location_type' => 'county',
            'max_price' => 35000000,
            'min_bedrooms' => 2,
            'property_type' => 'apartment',
        ]);

        $result = json_decode((string) (new UpdatePropertySearchPreferences($state))->handle(new Request([
            'max_price' => 20000000,
            'replace' => true,
        ])), true);

        $this->assertSame([$house->id], array_column($result['markers'], 'id'));
        $this->assertNull($state->fresh()->plan['preferences']['property_type']);
        $this->assertNull($state->fresh()->plan['preferences']['min_bedrooms']);
        $this->assertSame([], $result['relaxed']);
    }

    /** @param  array<string, mixed>  $preferences */
    protected function state(array $preferences): OnboardingState
    {
        return OnboardingState::create([
            'conversation_id' => (string) Str::uuid(),
            'flow' => 'property',
            'phase' => 'mapping',
            'plan' => SavePropertyPreferences::plan(PropertyPreferences::validate($preferences)),
        ]);
    }

    protected function listing(string $reference, float $latitude, float $longitude, int $cents, string $type): Property
    {
        $property = Property::create([
            'reference' => $reference,
            'address' => ucfirst($reference).' Address',
            'town' => 'Cork',
            'county' => 'Cork',
            'country' => 'IE',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'property_type' => $type,
            'bedrooms' => 3,
            'description' => 'Test property.',
            'status' => 'for_sale',
        ]);

        $property->priceRecords()->create([
            'record_type' => 'asking_price',
            'amount' => $cents,
            'currency' => 'EUR',
            'effective_date' => '2026-09-01',
        ]);

        return $property;
    }
}
