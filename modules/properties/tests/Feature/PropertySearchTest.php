<?php

namespace Modules\Properties\Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Properties\Imports\ListingImportService;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\PropertyPriceRecord;
use Modules\Properties\PropertySearch;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PropertySearchTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @var list<string>|null Nominatim's box order: south, north, west, east. */
    protected ?array $geocoded = null;

    protected int $geocoderStatus = 200;

    /**
     * A town search asks the geocoder for the place's outline, so every test
     * here would otherwise reach the real Nominatim. Answering "no such place"
     * by default keeps these tests about the database; the ones that care about
     * the box say so with `geocoderReturns()`.
     *
     * One stub reading mutable state, rather than a second `Http::fake()` per
     * test: the stubs merge and the first match keeps winning, so a later fake
     * for the same URL is silently ignored.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'nominatim.openstreetmap.org/*' => fn () => Http::response(
                $this->geocoded === null ? [] : [['boundingbox' => $this->geocoded]],
                $this->geocoderStatus,
            ),
        ]);
    }

    protected function geocoderReturns(float $south, float $north, float $west, float $east): void
    {
        $this->geocoded = [(string) $south, (string) $north, (string) $west, (string) $east];
    }

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

    public function test_importing_a_fixture_twice_does_not_duplicate_properties_or_price_records(): void
    {
        // One fixture rather than the seeder's whole folder: the seeder is a
        // loop over this, and importing four hundred listings twice would cost
        // the suite far more than the guarantee is worth.
        $importer = app(ListingImportService::class);
        $fixture = base_path('modules/properties/database/fixtures/buy-0002-daft.json');

        $first = $importer->import('daft', $fixture);
        $properties = Property::count();
        $prices = PropertyPriceRecord::count();

        $importer->import('daft', $fixture);

        $this->assertSame(20, $first->total_records);
        $this->assertGreaterThan(0, $properties);
        $this->assertSame($properties, Property::count());
        $this->assertSame($prices, PropertyPriceRecord::count());
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

    /**
     * `town` is whatever the listing called the area, not a settlement, so a
     * place spans several of them and none of them equals what a person types.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function localities(): array
    {
        return [
            'exact still matches' => ['Mallow', 'Mallow', true],
            'case is ignored' => ['mallow', 'Mallow', true],
            'leading words' => ['Cork City', 'Cork City Centre', true],
            'trailing words' => ['Blackrock', 'Old Blackrock Road', true],
            'surrounded' => ['Glanmire', 'Lower Glanmire Road', true],
            'after a comma' => ['Monaloo', 'Sandy Hill, Monaloo', true],
            'not a longer name' => ['Ballina', 'Ballinasloe', false],
            'not a longer name either' => ['Castletown', 'Castletownroche', false],
            'not an unrelated town' => ['Mallow', 'Youghal', false],
        ];
    }

    #[DataProvider('localities')]
    public function test_it_matches_a_locality_by_whole_word(string $searched, string $stored, bool $expected): void
    {
        $property = $this->property('ref', $stored, 'Cork', 'for_sale', 3, 'house');
        $this->price($property, 'asking_price', 20000000, '2026-09-01');

        $result = (new PropertySearch)->search($this->preferences($searched, 30000000));

        $this->assertSame($expected ? 1 : 0, $result['total']);
    }

    public function test_a_wildcard_in_the_search_term_is_not_a_wildcard(): void
    {
        $property = $this->property('ref', 'Mallow', 'Cork', 'for_sale', 3, 'house');
        $this->price($property, 'asking_price', 20000000, '2026-09-01');

        $result = (new PropertySearch)->search($this->preferences('%', 30000000));

        $this->assertSame(0, $result['total']);
    }

    public function test_a_city_gathers_up_the_suburbs_that_do_not_carry_its_name(): void
    {
        // Nobody labels a listing "Cork City"; they label it Glasheen or
        // Montenotte. Cork City's own outline is what ties them together.
        $this->geocoderReturns(51.8422992, 51.9372985, -8.5520003, -8.3893606);

        $inside = $this->property('glasheen', 'Glasheen', 'Cork', 'for_sale', 3, 'house');
        $inside->update(['latitude' => 51.89, 'longitude' => -8.49]);
        $this->price($inside, 'asking_price', 30000000, '2026-09-01');

        $outside = $this->property('bantry', 'Bantry', 'Cork', 'for_sale', 3, 'house');
        $outside->update(['latitude' => 51.68, 'longitude' => -9.45]);
        $this->price($outside, 'asking_price', 30000000, '2026-09-01');

        $result = (new PropertySearch)->search($this->preferences('Cork City', 40000000));

        $this->assertSame([$inside->id], array_column($result['markers'], 'id'));
    }

    public function test_a_town_is_still_found_when_the_geocoder_answers_with_a_street(): void
    {
        // Asked for Midleton the geocoder's best answer is a box around Mill
        // Road: a kilometre across, and containing none of the town's homes.
        // The name has to keep working or the box makes the search worse.
        $this->geocoderReturns(51.9161955, 51.9261955, -8.1806984, -8.1706984);

        $property = $this->property('midleton', 'Midleton', 'Cork', 'for_sale', 3, 'house');
        $property->update(['latitude' => 51.95, 'longitude' => -8.20]);
        $this->price($property, 'asking_price', 30000000, '2026-09-01');

        $result = (new PropertySearch)->search($this->preferences('Midleton', 40000000));

        $this->assertSame([$property->id], array_column($result['markers'], 'id'));
    }

    public function test_a_box_that_overshoots_the_county_does_not_cross_it(): void
    {
        // Blackrock is a suburb of Cork and of Dublin. A box drawn wide enough
        // to cover one must not collect the other.
        $this->geocoderReturns(51.0, 54.0, -10.0, -6.0);

        $cork = $this->property('cork-blackrock', 'Blackrock', 'Cork', 'for_sale', 3, 'house');
        $this->price($cork, 'asking_price', 30000000, '2026-09-01');

        $dublin = $this->property('dublin-blackrock', 'Blackrock', 'Dublin', 'for_sale', 3, 'house');
        $dublin->update(['latitude' => 53.30, 'longitude' => -6.18]);
        $this->price($dublin, 'asking_price', 30000000, '2026-09-01');

        $result = (new PropertySearch)->search(
            $this->preferences('Blackrock', 40000000, county: 'Cork')
        );

        $this->assertSame([$cork->id], array_column($result['markers'], 'id'));
    }

    public function test_a_county_is_matched_by_name_and_never_by_a_box(): void
    {
        // A rectangle around County Cork reaches into Kerry, Limerick and
        // Waterford, so the geocoder is not asked at all for a county.
        $property = $this->property('cork', 'Mallow', 'Cork', 'for_sale', 3, 'house');
        $this->price($property, 'asking_price', 30000000, '2026-09-01');

        (new PropertySearch)->search($this->preferences('Cork', 40000000, locationType: 'county'));

        Http::assertNothingSent();
    }

    public function test_a_geocoder_that_never_answers_leaves_the_search_working(): void
    {
        // A timeout raises rather than returning a response. This sits inside
        // the search every property page runs, so uncaught it does not fall
        // back to the name match, it takes the page down.
        Http::fake([
            'nominatim.openstreetmap.org/*' => fn () => throw new ConnectionException('timed out'),
        ]);

        $property = $this->property('mallow', 'Mallow', 'Cork', 'for_sale', 3, 'house');
        $this->price($property, 'asking_price', 30000000, '2026-09-01');

        $result = (new PropertySearch)->search($this->preferences('Mallow', 40000000));

        $this->assertSame([$property->id], array_column($result['markers'], 'id'));
    }

    public function test_an_unreachable_geocoder_leaves_the_search_working(): void
    {
        $this->geocoderStatus = 503;

        $property = $this->property('mallow', 'Mallow', 'Cork', 'for_sale', 3, 'house');
        $this->price($property, 'asking_price', 30000000, '2026-09-01');

        $result = (new PropertySearch)->search($this->preferences('Mallow', 40000000));

        // Degraded to the name match rather than returning nothing: a geocoder
        // that is briefly down must not empty the map.
        $this->assertSame([$property->id], array_column($result['markers'], 'id'));
    }

    public function test_it_carries_the_listing_back_to_its_portal(): void
    {
        $listed = $this->property('listed', 'Mallow', 'Cork', 'for_sale', 3, 'house');
        $this->price($listed, 'asking_price', 20000000, '2026-09-01');
        $listed->listings()->create([
            'provider' => 'daft',
            'provider_listing_id' => '123',
            'url' => 'https://www.daft.ie/for-sale/listed-address/123',
            'title' => 'Listed Address',
            'status' => 'active',
            'last_seen_on' => '2026-09-01',
        ]);

        $unlisted = $this->property('unlisted', 'Mallow', 'Cork', 'for_sale', 3, 'house');
        $this->price($unlisted, 'asking_price', 21000000, '2026-09-01');

        $markers = collect((new PropertySearch)->search($this->preferences('Mallow', 30000000)))
            ->get('markers');

        // Every other field on the card is a copy taken at import time, so this
        // is the only way back to something live. A property we hold without a
        // listing says so rather than offering a link to nowhere.
        $this->assertSame(
            ['provider' => 'daft', 'url' => 'https://www.daft.ie/for-sale/listed-address/123'],
            $markers[0]['source'],
        );
        $this->assertNull($markers[1]['source']);
    }

    /** @return array<string, mixed> */
    private function preferences(string $location, int $maxPrice, ?int $minimumBedrooms = null, ?string $propertyType = null, string $locationType = 'town', ?string $county = null): array
    {
        return [
            'location' => $location,
            'location_type' => $locationType,
            'county' => $county,
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
