<?php

namespace Modules\Properties\Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Modules\Properties\Database\Seeders\PropertySeeder;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\PropertyPriceRecord;
use Modules\Properties\PropertySearch;
use Tests\TestCase;

class PropertySearchTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_returns_only_available_homes_with_the_latest_asking_price_within_the_budget(): void
    {
        $matched = $this->property('matched', 'Mallow', 'Cork', 'for_sale', 3, 'house');
        $this->price($matched, 'asking_price', 31000000, '2026-08-01');
        $this->price($matched, 'asking_price', 28500000, '2026-09-01');

        $tooExpensive = $this->property('too-expensive', 'Mallow', 'Cork', 'for_sale', 3, 'house');
        $this->price($tooExpensive, 'asking_price', 35000000, '2026-09-01');

        $sold = $this->property('sold', 'Mallow', 'Cork', 'sold', 3, 'house');
        $this->price($sold, 'asking_price', 20000000, '2026-09-01');
        $this->price($sold, 'sale', 21000000, '2026-09-02');

        $withdrawn = $this->property('withdrawn', 'Mallow', 'Cork', 'withdrawn', 3, 'house');
        $this->price($withdrawn, 'asking_price', 20000000, '2026-09-01');

        $result = (new PropertySearch)->search($this->preferences('Mallow', 28500000));

        $this->assertSame(1, $result['total']);
        $this->assertSame([[
            'id' => $matched->id,
            'name' => 'Matched Address',
            'asking_price' => 28500000,
            'bedrooms' => 3,
            'property_type' => 'house',
        ]], array_map(fn (array $property): array => [
            'id' => $property['id'],
            'name' => $property['name'],
            'asking_price' => $property['asking_price'],
            'bedrooms' => $property['bedrooms'],
            'property_type' => $property['property_type'],
        ], $result['markers']));
    }

    public function test_it_filters_inclusively_by_bedrooms_and_property_type(): void
    {
        $included = $this->property('included', 'Cork', 'Cork', 'for_sale', 3, 'house');
        $this->price($included, 'asking_price', 35000000, '2026-09-01');

        $twoBedroom = $this->property('two-bedroom', 'Cork', 'Cork', 'for_sale', 2, 'house');
        $this->price($twoBedroom, 'asking_price', 30000000, '2026-09-01');

        $apartment = $this->property('apartment', 'Cork', 'Cork', 'for_sale', 3, 'apartment');
        $this->price($apartment, 'asking_price', 30000000, '2026-09-01');

        $result = (new PropertySearch)->search($this->preferences('Cork', 35000000, 3, 'house'));

        $this->assertSame(1, $result['total']);
        $this->assertSame([$included->id], array_column($result['markers'], 'id'));
    }

    public function test_it_matches_counties_case_insensitively_and_limits_the_results_to_twenty(): void
    {
        foreach (range(1, 21) as $number) {
            $property = $this->property("cork-{$number}", 'Town '.$number, 'Cork', 'for_sale', 2, 'house');
            $this->price($property, 'asking_price', 20000000 + $number, '2026-09-01');
        }

        $result = (new PropertySearch)->search($this->preferences('cOrK', 30000000, locationType: 'county'));

        $this->assertSame(21, $result['total']);
        $this->assertCount(20, $result['markers']);
        $this->assertSame(20000001, $result['markers'][0]['asking_price']);
        $this->assertSame(20000020, $result['markers'][19]['asking_price']);
    }

    public function test_its_demo_seeder_does_not_duplicate_properties_or_price_records(): void
    {
        $this->seed(PropertySeeder::class);
        $this->seed(PropertySeeder::class);

        $this->assertSame(20, Property::count());
        $this->assertSame(22, PropertyPriceRecord::count());
        $this->assertSame(10, Property::where('town', 'Cork')->where('status', 'for_sale')->count());
        $this->assertSame(15, Property::where('county', 'Cork')->where('status', 'for_sale')->count());
    }

    public function test_it_rechecks_a_saved_result_against_the_current_price_and_status(): void
    {
        $property = $this->property('rechecked', 'Mallow', 'Cork', 'for_sale', 3, 'house');
        $this->price($property, 'asking_price', 28000000, '2026-09-01');

        $search = new PropertySearch;
        $preferences = $this->preferences('Mallow', 30000000);
        $initial = $search->search($preferences);

        $this->price($property, 'asking_price', 35000000, '2026-09-02');
        $refreshed = $search->search($preferences, array_column($initial['markers'], 'id'));

        $this->assertSame([], $refreshed['markers']);
    }

    /** @return array<string, mixed> */
    private function preferences(string $location, int $maxPrice, ?int $minimumBedrooms = null, ?string $propertyType = null, string $locationType = 'town'): array
    {
        return [
            'location' => $location,
            'location_type' => $locationType,
            'county' => null,
            'max_price' => $maxPrice,
            'min_bedrooms' => $minimumBedrooms,
            'property_type' => $propertyType,
        ];
    }

    private function property(string $reference, string $town, string $county, string $status, int $bedrooms, string $propertyType): Property
    {
        return Property::create([
            'reference' => $reference,
            'address' => ucfirst($reference).' Address',
            'town' => $town,
            'county' => $county,
            'country' => 'IE',
            'latitude' => 51.9,
            'longitude' => -8.4,
            'property_type' => $propertyType,
            'bedrooms' => $bedrooms,
            'description' => 'Test property.',
            'status' => $status,
        ]);
    }

    private function price(Property $property, string $recordType, int $amount, string $effectiveDate): void
    {
        $property->priceRecords()->create([
            'record_type' => $recordType,
            'amount' => $amount,
            'currency' => 'EUR',
            'effective_date' => $effectiveDate,
        ]);
    }
}
