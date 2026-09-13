<?php

namespace Modules\Properties\Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Modules\Properties\Imports\ListingImportService;
use Modules\Properties\Imports\MyHomeCorkListingMapper;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\PropertyListing;
use Modules\Properties\Models\PropertyPriceRecord;
use Modules\Properties\PropertyKind;
use Modules\Properties\PropertyPreferences;
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
            'highlight' => 'typical',
        ]], array_map(fn (array $property): array => [
            'id' => $property['id'],
            'name' => $property['name'],
            'asking_price' => $property['asking_price'],
            'bedrooms' => $property['bedrooms'],
            'property_type' => $property['property_type'],
            'highlight' => $property['highlight'],
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

    public function test_it_returns_every_match_when_the_total_is_within_the_full_limit(): void
    {
        config(['properties.search_limit' => 50, 'properties.search_limit_full' => 250]);

        foreach (range(1, 51) as $number) {
            $property = $this->property("cork-{$number}", 'Town '.$number, 'Cork', 'for_sale', 2, 'house');
            $this->price($property, 'asking_price', 20000000 + $number, '2026-09-01');
        }

        $result = (new PropertySearch)->search($this->preferences('cOrK', 30000000, locationType: 'county'));

        $this->assertSame(51, $result['total']);
        $this->assertCount(51, $result['markers']);
    }

    public function test_it_caps_the_map_when_many_homes_match(): void
    {
        config(['properties.search_limit' => 50, 'properties.search_limit_full' => 40]);

        foreach (range(1, 51) as $number) {
            $property = $this->property("cork-cap-{$number}", 'Town '.$number, 'Cork', 'for_sale', 2, 'house');
            $this->price($property, 'asking_price', 20000000 + $number, '2026-09-01');
        }

        $result = (new PropertySearch)->search($this->preferences('cOrK', 30000000, locationType: 'county'));

        $this->assertSame(51, $result['total']);
        $this->assertCount(50, $result['markers']);
        $this->assertSame(20000001, $result['markers'][0]['asking_price']);
        $this->assertSame(20000050, $result['markers'][49]['asking_price']);
    }

    public function test_importing_a_myhome_fixture_twice_does_not_duplicate_properties_or_price_records(): void
    {
        $importer = app(ListingImportService::class);
        $fixture = base_path('modules/properties/database/fixtures/myhome-cork-lite.json');

        $first = $importer->import('myhome', $fixture);
        $properties = Property::count();
        $prices = PropertyPriceRecord::count();

        $importer->import('myhome', $fixture);

        $this->assertSame(5, $first->total_records);
        $this->assertGreaterThan(0, $properties);
        $this->assertSame($properties, Property::count());
        $this->assertSame($prices, PropertyPriceRecord::count());
    }

    public function test_myhome_imports_descriptions_photos_and_listing_urls(): void
    {
        $importer = app(ListingImportService::class);
        $fixture = base_path('modules/properties/database/fixtures/myhome-cork-lite.json');

        $importer->import('myhome', $fixture);

        $property = Property::query()->where('reference', 'myhome:4996585')->first();

        $this->assertNotNull($property);
        $this->assertNotNull($property->description);
        $this->assertSame('Mallow', $property->town);
        $this->assertSame('Cork', $property->county);
        $this->assertSame('P51FC6K', $property->eircode);

        $listing = PropertyListing::query()
            ->where('provider', 'myhome')
            ->where('provider_listing_id', '4996585')
            ->first();

        $this->assertNotNull($listing);
        $this->assertStringContainsString('myhome.ie', (string) $listing->url);
        $this->assertGreaterThan(1, $listing->media()->count());
        $this->assertSame('Savills - Cork', $listing->metadata['agent'] ?? null);
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

    public function test_a_minimum_rating_that_admits_every_grade_does_not_exclude_unrated_homes(): void
    {
        $unrated = $this->property('unrated', 'Cork', 'Cork', 'for_sale', 3, 'house');
        $this->price($unrated, 'asking_price', 30000000, '2026-09-01');

        $rated = $this->property('rated', 'Cork', 'Cork', 'for_sale', 3, 'house');
        $rated->update(['ber_rating' => 'C2']);
        $this->price($rated, 'asking_price', 30000000, '2026-09-01');

        // "G or better" is every rating there is, so it is not a filter. Most
        // homes publish no rating at all, and treating it as one silently
        // emptied the results for anyone answering "any BER rating".
        $any = (new PropertySearch)->search(
            $this->preferences('Cork', 30000000, 3, 'house') + ['minimum_ber_rating' => 'G']
        );

        $this->assertSame(2, $any['total']);

        $strict = (new PropertySearch)->search(
            $this->preferences('Cork', 30000000, 3, 'house') + ['minimum_ber_rating' => 'B1']
        );

        $this->assertSame(0, $strict['total']);
    }

    public function test_it_treats_sites_as_land_and_keeps_them_out_of_home_filters(): void
    {
        $site = $this->property('site-plot', 'Mallow', 'Cork', 'for_sale', 3, 'other');
        $site->update([
            'bedrooms' => null,
            'address' => 'Site @ Farrandoyle',
            'floor_area_sqm' => 2105,
        ]);
        $this->price($site, 'asking_price', 6000000, '2026-09-01');
        $site->listings()->create([
            'provider' => 'myhome',
            'provider_listing_id' => 'site-1',
            'status' => 'active',
            'title' => 'Site @ Farrandoyle',
            'metadata' => ['raw_property_type' => 'Site'],
            'last_seen_on' => now(),
        ]);

        $house = $this->property('house', 'Mallow', 'Cork', 'for_sale', 3, 'house');
        $this->price($house, 'asking_price', 20000000, '2026-09-01');

        $land = (new PropertySearch)->search($this->preferences('Mallow', 30000000, propertyType: 'land'));

        $this->assertSame(1, $land['total']);
        $this->assertSame('land', $land['markers'][0]['property_type']);
        $this->assertNull($land['markers'][0]['bedrooms']);
        $this->assertSame('0.52 acres', $land['markers'][0]['size_label']);

        $homes = (new PropertySearch)->search($this->preferences('Mallow', 30000000, 3, 'house'));

        $this->assertSame([$house->id], array_column($homes['markers'], 'id'));
    }

    public function test_it_treats_euro_amounts_as_cents_when_the_model_forgets_to_convert(): void
    {
        $this->assertSame(20000000, PropertyPreferences::asCents(200000));
        $this->assertSame(20000000, PropertyPreferences::asCents(20000000));
        $this->assertSame(8000000, PropertyPreferences::validate($this->preferences('Cork', 80000))['max_price']);
    }

    public function test_it_widens_leftover_filters_when_the_strict_search_is_empty(): void
    {
        $house = $this->property('any-house', 'Cork', 'Cork', 'for_sale', 3, 'house');
        $this->price($house, 'asking_price', 15000000, '2026-09-01');

        $empty = (new PropertySearch)->search($this->preferences('Cork', 20000000, 1, 'apartment'));
        $widened = (new PropertySearch)->searchOrWiden($this->preferences('Cork', 20000000, 1, 'apartment'));

        $this->assertSame(0, $empty['total']);
        $this->assertSame(1, $widened['total']);
        $this->assertSame($house->id, $widened['markers'][0]['id']);
        $this->assertSame(['property_type'], $widened['relaxed']);
        $this->assertNull($widened['preferences']['property_type']);
        $this->assertSame(1, $widened['preferences']['min_bedrooms']);
    }

    public function test_it_clears_home_filters_when_the_type_is_land(): void
    {
        $preferences = PropertyPreferences::validate($this->preferences('Cork', 8000000, 3, 'land') + [
            'minimum_ber_rating' => 'B3',
        ]);

        $this->assertSame('land', $preferences['property_type']);
        $this->assertNull($preferences['min_bedrooms']);
        $this->assertNull($preferences['minimum_ber_rating']);
    }

    public function test_it_sorts_by_price_per_square_metre_and_exposes_the_rate(): void
    {
        $betterRate = $this->property('better-rate', 'Cork', 'Cork', 'for_sale', 3, 'house');
        $betterRate->update(['floor_area_sqm' => 200]);
        $this->price($betterRate, 'asking_price', 20000000, '2026-09-01');

        $worseRate = $this->property('worse-rate', 'Cork', 'Cork', 'for_sale', 3, 'house');
        $worseRate->update(['floor_area_sqm' => 50]);
        $this->price($worseRate, 'asking_price', 15000000, '2026-09-01');

        $byPrice = (new PropertySearch)->search($this->preferences('Cork', 30000000));

        $this->assertSame([$worseRate->id, $betterRate->id], array_column($byPrice['markers'], 'id'));
        $this->assertSame(300000, $byPrice['markers'][0]['price_per_sqm']);
        $this->assertSame(100000, $byPrice['markers'][1]['price_per_sqm']);

        $byRate = (new PropertySearch)->search($this->preferences('Cork', 30000000) + ['sort' => 'price_per_sqm']);

        $this->assertSame([$betterRate->id, $worseRate->id], array_column($byRate['markers'], 'id'));
    }

    public function test_it_maps_myhome_sites_to_land(): void
    {
        $this->assertSame('land', PropertyKind::fromRaw('Site', 'Alderwood, Carrigrohane'));
        $this->assertSame('land', PropertyKind::fromRaw(null, 'Site @ Ballydevlin'));
        $this->assertSame('house', PropertyKind::fromRaw('Detached House', '28 Newlyn Vale'));

        $mapped = (new MyHomeCorkListingMapper)->map([
            'propertyId' => 'site-test',
            'displayAddress' => 'Site at Bridge Street, Ballineen, Co. Cork',
            'lat' => 51.7,
            'lon' => -8.9,
            'price' => 80000,
            'propertyType' => 'Site',
            'beds' => '',
            'localityName' => 'Ballineen',
            'regionName' => 'Cork',
        ]);

        $this->assertSame('land', $mapped['property_type']);
        $this->assertNull($mapped['bedrooms']);
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

    public function test_it_excludes_listings_at_null_island(): void
    {
        $valid = $this->property('valid', 'Mallow', 'Cork', 'for_sale', 3, 'house');
        $this->price($valid, 'asking_price', 28500000, '2026-09-01');

        $invalid = $this->property('null-island', 'Mallow', 'Cork', 'for_sale', 2, 'house');
        $invalid->update(['latitude' => 0, 'longitude' => 0]);
        $this->price($invalid, 'asking_price', 36500000, '2026-09-01');

        $result = (new PropertySearch)->search($this->preferences('Mallow', 40000000));

        $this->assertSame(1, $result['total']);
        $this->assertSame([$valid->id], array_column($result['markers'], 'id'));
    }

    public function test_it_drops_unsafe_listing_urls_and_images(): void
    {
        $matched = $this->property('matched', 'Mallow', 'Cork', 'for_sale', 3, 'house');
        $this->price($matched, 'asking_price', 28500000, '2026-09-01');

        $listing = PropertyListing::create([
            'property_id' => $matched->id,
            'provider' => 'myhome',
            'provider_listing_id' => 'unsafe-1',
            'title' => 'Unsafe listing',
            'url' => 'javascript:alert(1)',
            'status' => 'active',
            'listed_on' => today(),
            'last_seen_on' => today(),
            'metadata' => [],
        ]);
        $listing->media()->createMany([
            ['url' => 'javascript:alert(1)', 'alt_text' => null, 'position' => 0],
            ['url' => 'https://example.test/photo.jpg', 'alt_text' => null, 'position' => 1],
        ]);

        $marker = (new PropertySearch)->search($this->preferences('Mallow', 28500000))['markers'][0];

        $this->assertNull($marker['url']);
        $this->assertNull($marker['details']['url']);
        $this->assertSame(['https://example.test/photo.jpg'], $marker['images']);
    }

    public function test_it_loads_one_listing_by_id_and_rejects_missing_or_sold_homes(): void
    {
        $matched = $this->property('listed', 'Mallow', 'Cork', 'for_sale', 3, 'house');
        $this->price($matched, 'asking_price', 28500000, '2026-09-01');

        $sold = $this->property('sold-home', 'Mallow', 'Cork', 'sold', 3, 'house');
        $this->price($sold, 'asking_price', 20000000, '2026-09-01');

        $search = new PropertySearch;

        $this->assertSame($matched->id, $search->listing($matched->id)['id'] ?? null);
        $this->assertSame(28500000, $search->listing($matched->id)['asking_price'] ?? null);
        $this->assertNull($search->listing($sold->id));
        $this->assertNull($search->listing(999999));
    }

    public function test_it_rejects_urls_with_credentials_or_control_characters(): void
    {
        $this->assertNull(safe_http_url('javascript:alert(1)'));
        $this->assertNull(safe_http_url("https://example.test/photo.jpg\njavascript:alert(1)"));
        $this->assertNull(safe_http_url('https://user:pass@example.test/photo.jpg'));
        $this->assertSame('https://example.test/photo.jpg', safe_http_url('https://example.test/photo.jpg'));
    }

    public function test_compact_tool_response_omits_heavy_listing_fields(): void
    {
        $matched = $this->property('matched', 'Mallow', 'Cork', 'for_sale', 3, 'house');
        $this->price($matched, 'asking_price', 28500000, '2026-09-01');

        $view = (new PropertySearch)->search($this->preferences('Mallow', 28500000));
        $view['markers'][0]['description'] = str_repeat('Long description. ', 200);
        $view['markers'][0]['images'] = ['https://example.test/one.jpg', 'https://example.test/two.jpg'];
        $view['markers'][0]['details'] = [
            'description' => str_repeat('Details. ', 200),
            'url' => 'https://example.test/listing',
        ];

        $compact = json_decode((new PropertySearch)->compactToolResponse($view), true, flags: JSON_THROW_ON_ERROR);
        $marker = $compact['markers'][0];

        $this->assertSame(1, $compact['shown']);
        $this->assertArrayNotHasKey('description', $marker);
        $this->assertArrayNotHasKey('details', $marker);
        $this->assertSame(['https://example.test/one.jpg'], $marker['images']);
        $this->assertSame('€285,000', $marker['price']);
        $this->assertSame(28500000, $marker['asking_price']);
        $this->assertSame('€90,000', PropertySearch::euroLabel(9000000));
        $this->assertStringContainsString('already in euro', $compact['note']);
        $this->assertLessThan(
            strlen(json_encode($view, JSON_THROW_ON_ERROR)),
            strlen(json_encode($compact, JSON_THROW_ON_ERROR)),
        );
    }
}
