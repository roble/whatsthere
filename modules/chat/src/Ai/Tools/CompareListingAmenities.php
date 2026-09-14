<?php

namespace Modules\Chat\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Modules\Chat\Models\OnboardingState;
use Modules\Properties\PropertySearch;
use Stringable;

/**
 * Rank the listings already on the map by how close real amenities sit.
 *
 * The model used to call find_places with a listing address as the area. That
 * geocodes the hamlet, searches a tight box, and burns the step budget on one
 * rural school. Coordinates are already on every pin; this walks from those
 * and returns a winner the map can show.
 */
class CompareListingAmenities implements Tool
{
    public const string NAME = 'compare_listing_amenities';

    public const int MAX_LISTINGS = 8;

    public function __construct(protected OnboardingState $state) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): Stringable|string
    {
        return 'Rank the listings already on the map by distance to nearby hospitals, schools, bus stops, or other mapped places. Call this once when the visitor asks which home or plot has the best, closest, or most convenient school, hospital, clinic, bus stop, train station, park or shop. Uses each listing\'s coordinates — never geocode an address. Omit listing_ids to compare the current results. Pass listing_ids only to restrict the comparison.';
    }

    public function handle(Request $request): Stringable|string
    {
        $categories = $this->categories($request['categories'] ?? []);

        if ($categories === []) {
            return 'No recognised place kinds were given, so the listings were left as they are.';
        }

        $listings = $this->listings($request['listing_ids'] ?? []);

        if ($listings === []) {
            return 'There are no listings on the map to compare. Search for homes or land first.';
        }

        $places = (new FindPlaces($this->conversationId()))->aroundPoints(
            array_map(
                fn (array $listing): array => [
                    'lat' => (float) $listing['lat'],
                    'lon' => (float) $listing['lon'],
                ],
                $listings,
            ),
            $categories,
        );

        if ($places === null) {
            return 'The map data service could not be reached, so the listings were left as they are.';
        }

        $finder = new FindPlaces;
        $rankings = [];

        foreach ($listings as $listing) {
            $nearest = [];
            $missing = [];
            $combined = 0;

            foreach ($categories as $category) {
                $match = $this->nearestOf($places, $listing, $category, $finder);

                if ($match === null) {
                    $missing[] = $category;

                    continue;
                }

                $nearest[$category] = $match;
                $combined += $match['distance_m'];
            }

            $rankings[] = [
                'id' => $listing['id'],
                'name' => $listing['name'],
                'town' => $listing['town'] ?? null,
                'price' => PropertySearch::euroLabel($listing['asking_price'] ?? null),
                'complete' => $missing === [],
                'missing' => $missing,
                'combined_m' => $missing === [] ? $combined : null,
                'combined' => $missing === [] ? $this->formatDistance($combined) : null,
                'nearest' => $nearest,
            ];
        }

        usort($rankings, function (array $left, array $right): int {
            return [$right['complete'], $left['combined_m'] ?? PHP_INT_MAX]
                <=> [$left['complete'], $right['combined_m'] ?? PHP_INT_MAX];
        });

        $winner = $rankings[0];
        $winnerListing = collect($listings)->firstWhere('id', $winner['id']) ?? $listings[0];
        $markers = $this->mapMarkers($listings, $winnerListing, $winner['nearest'] ?? []);
        $kinds = implode(', ', $categories);

        return json_encode([
            'label' => 'Closest '.$kinds,
            'categoryKey' => 'amenities',
            'category' => 'nearby places',
            'bbox' => $this->bbox($markers),
            'winner' => [
                'id' => $winner['id'],
                'name' => $winner['name'],
                'complete' => $winner['complete'],
                'combined' => $winner['combined'],
                'missing' => $winner['missing'],
            ],
            'rankings' => $rankings,
            'markers' => $markers,
            'note' => 'Ranked by shortest combined straight-line distance to the nearest of each requested kind. Listings missing a kind rank after complete ones. Distances are not driving time. Name the winner by its exact address. Only mention places listed here — do not invent a hospital, school or stop.',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    protected function categories(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : [];

        return array_values(array_filter(
            array_unique(array_map(
                fn (mixed $category): string => trim((string) $category),
                $values,
            )),
            fn (string $category): bool => isset(FindPlaces::CATEGORIES[$category]),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function listings(mixed $rawIds): array
    {
        $this->state->refresh();
        $preferences = $this->state->plan['preferences'] ?? null;

        if (! is_array($preferences)) {
            return [];
        }

        try {
            $markers = (new PropertySearch)->search($preferences)['markers'] ?? [];
        } catch (\Throwable) {
            return [];
        }

        $wanted = array_values(array_filter(
            array_map(
                fn (mixed $id): int => (int) $id,
                is_array($rawIds) ? $rawIds : [],
            ),
            fn (int $id): bool => $id > 0,
        ));

        if ($wanted !== []) {
            $byId = collect($markers)->keyBy('id');
            $search = new PropertySearch;
            $markers = [];

            foreach (array_slice($wanted, 0, self::MAX_LISTINGS) as $id) {
                $listing = $byId->get($id) ?? $search->listing($id);

                if (is_array($listing)) {
                    $markers[] = $listing;
                }
            }

            return $markers;
        }

        return array_slice($markers, 0, self::MAX_LISTINGS);
    }

    /**
     * @param  list<array<string, mixed>>  $places
     * @param  array<string, mixed>  $listing
     * @return array{name: string, distance_m: int, distance: string, walk_min: int, lat: float, lon: float}|null
     */
    protected function nearestOf(array $places, array $listing, string $category, FindPlaces $finder): ?array
    {
        $closest = null;
        $closestDistance = PHP_INT_MAX;

        foreach ($places as $place) {
            if (($place['categoryKey'] ?? null) !== $category) {
                continue;
            }

            $distance = $finder->distanceMetres(
                (float) $listing['lat'],
                (float) $listing['lon'],
                (float) $place['lat'],
                (float) $place['lon'],
            );

            if ($distance > FindPlaces::radiusMetres($category) || $distance >= $closestDistance) {
                continue;
            }

            $closestDistance = $distance;
            $closest = $place;
        }

        if ($closest === null) {
            return null;
        }

        return [
            'name' => $closest['name'],
            'distance_m' => $closestDistance,
            'distance' => $this->formatDistance($closestDistance),
            'walk_min' => max(1, (int) round($closestDistance / 80)),
            'lat' => (float) $closest['lat'],
            'lon' => (float) $closest['lon'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $listings
     * @param  array<string, mixed>  $winner
     * @param  array<string, array<string, mixed>>  $nearest
     * @return list<array<string, mixed>>
     */
    protected function mapMarkers(array $listings, array $winner, array $nearest): array
    {
        $markers = [];

        foreach ($listings as $listing) {
            $marker = PropertySearch::slimMarker($listing);
            $marker['categoryKey'] = 'property';
            $marker['highlight'] = ($listing['id'] ?? null) === ($winner['id'] ?? null)
                ? 'match'
                : ($listing['highlight'] ?? 'typical');
            $markers[] = $marker;
        }

        foreach ($nearest as $category => $place) {
            $markers[] = [
                'lat' => $place['lat'],
                'lon' => $place['lon'],
                'name' => $place['name'],
                'categoryKey' => $category,
                'distance_m' => $place['distance_m'],
            ];
        }

        return $markers;
    }

    /**
     * @param  list<array<string, mixed>>  $markers
     * @return list<string>
     */
    protected function bbox(array $markers): array
    {
        $latitudes = array_map(fn (array $marker): float => (float) $marker['lat'], $markers);
        $longitudes = array_map(fn (array $marker): float => (float) $marker['lon'], $markers);

        return [
            (string) (min($longitudes) - 0.01),
            (string) (min($latitudes) - 0.01),
            (string) (max($longitudes) + 0.01),
            (string) (max($latitudes) + 0.01),
        ];
    }

    protected function formatDistance(int $metres): string
    {
        return $metres < 1000
            ? $metres.' m'
            : number_format($metres / 1000, $metres < 10000 ? 1 : 0).' km';
    }

    protected function conversationId(): ?string
    {
        $id = $this->state->getKey();

        return $id === null ? null : (string) $id;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'categories' => $schema->array()
                ->items($schema->string()->enum(array_keys(FindPlaces::CATEGORIES)))
                ->description('The kinds of place to score, e.g. hospital, school, bus_stop. Use every kind the visitor named.')
                ->required(),
            'listing_ids' => $schema->array()
                ->items($schema->integer())
                ->description('Optional listing ids to compare. Omit to use the listings currently on the map.'),
        ];
    }
}
