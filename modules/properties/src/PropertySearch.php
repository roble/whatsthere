<?php

namespace Modules\Properties;

use Illuminate\Database\Eloquent\Builder;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\PropertyPriceRecord;

class PropertySearch
{
    /** @param array<string, mixed> $preferences
     * @param  list<int>|null  $savedIds
     * @return array<string, mixed>
     */
    public function search(array $preferences, ?array $savedIds = null): array
    {
        $filters = PropertyPreferences::validate($preferences);
        $price = PropertyPriceRecord::query()->select('amount')
            ->whereColumn('property_id', 'properties.id')
            ->where('record_type', 'asking_price')->where('effective_date', '<=', today())
            ->orderByDesc('effective_date')->orderByDesc('id')->limit(1);

        $query = Property::query()->with(['askingPrice', 'activeListing.media'])
            ->where('status', 'for_sale')->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereHas('askingPrice', fn (Builder $query) => $query->where('currency', 'EUR'))
            ->where($price, '<=', $filters['max_price']);

        $this->whereInPlace($query, $filters);

        if ($filters['location_type'] === 'town' && $filters['county'] !== null) {
            // Applied outside the name-or-box group on purpose: it is what
            // stops a bounding box that overshoots the county line from
            // dragging in the Blackrock or Rochestown of somewhere else.
            $this->whereLocality($query, 'county', $filters['county']);
        }
        if ($filters['min_bedrooms'] !== null) {
            $query->where('bedrooms', '>=', $filters['min_bedrooms']);
        }
        if ($filters['property_type'] !== null) {
            $query->where('property_type', $filters['property_type']);
        }
        if ($filters['minimum_ber_rating'] !== null) {
            $ratings = PropertyPreferences::ratingsAtOrAbove($filters['minimum_ber_rating']);

            // A minimum that admits every rating is not a filter at all. Left
            // as a whereIn it would still drop every property whose rating is
            // unpublished, so "any BER" would quietly return nothing.
            if (count($ratings) < count(PropertyPreferences::BER_RATINGS)) {
                $query->whereIn('ber_rating', $ratings);
            }
        }

        $total = (clone $query)->count();
        if ($savedIds !== null) {
            $query->whereIn('id', $savedIds);
        }
        $properties = $query->orderBy($price)->orderBy('properties.id')->limit(20)->get();
        $markers = $properties->map(function (Property $property): array {
            return [
                'id' => $property->id,
                'name' => $property->address,
                'address' => $property->address,
                'town' => $property->town,
                'county' => $property->county,
                'lat' => $property->latitude,
                'lon' => $property->longitude,
                'asking_price' => $property->askingPrice->amount,
                'currency' => $property->askingPrice->currency,
                'bedrooms' => $property->bedrooms,
                'property_type' => $property->property_type,
                'ber_rating' => $property->ber_rating,
                'bathrooms' => $property->bathrooms,
                'floor_area_sqm' => $property->floor_area_sqm,
                'description' => $property->description,
                'images' => $property->activeListing?->media->pluck('url')->all() ?: [
                    '/modules/properties/images/cork-home-exterior.png',
                    '/modules/properties/images/cork-home-living-room.png',
                    '/modules/properties/images/cork-home-kitchen.png',
                ],
                'categoryKey' => 'property',
                // Where the listing actually lives. Everything on the card is
                // a copy taken at import time, so this is the only way to see
                // whether it is still for sale, book a viewing, or read the
                // parts of the ad we do not store.
                'source' => $property->activeListing?->url === null ? null : [
                    'provider' => $property->activeListing->provider,
                    'url' => $property->activeListing->url,
                ],
                'details' => [
                    'address' => "{$property->address}, {$property->town}, {$property->county}",
                    'description' => $property->description,
                ],
            ];
        })->all();

        $lats = array_column($markers, 'lat');
        $lons = array_column($markers, 'lon');

        return [
            'label' => 'Properties for sale in '.$filters['location'],
            'categoryKey' => 'property',
            'total' => $total,
            'markers' => $markers,
            'bbox' => $markers === [] ? ['-10.7', '51.3', '-5.4', '55.5'] : [
                (string) (min($lons) - 0.005), (string) (min($lats) - 0.005),
                (string) (max($lons) + 0.005), (string) (max($lats) + 0.005),
            ],
        ];
    }

    /**
     * Narrow to the place the visitor named.
     *
     * A county is matched by name and nothing else: the column holds clean
     * county names, and a box around County Cork is a rectangle that reaches
     * well into Kerry, Limerick and Waterford.
     *
     * A town is matched by name **or** by falling inside the place's own
     * bounding box, because neither alone is enough. "Cork City" is a name no
     * listing carries, and the box finds the 53 homes across Glasheen, Douglas,
     * Bishopstown and the rest that a local means by it. "Midleton" is the
     * reverse: the geocoder's best answer is a box around Mill Road, a
     * kilometre across and containing nothing, while the name finds all eight.
     * Taking either match keeps both kinds of question working.
     *
     * @param  array<string, mixed>  $filters
     */
    private function whereInPlace(Builder $query, array $filters): void
    {
        if ($filters['location_type'] !== 'town') {
            $this->whereLocality($query, $filters['location_type'], $filters['location']);

            return;
        }

        $bounds = (new PlaceBounds)->for($filters['location'], $filters['county']);

        if ($bounds === null) {
            $this->whereLocality($query, 'town', $filters['location']);

            return;
        }

        [$south, $north, $west, $east] = $bounds;

        $query->where(function (Builder $query) use ($filters, $south, $north, $west, $east): void {
            $this->whereLocality($query, 'town', $filters['location']);

            $query->orWhere(fn (Builder $query) => $query
                ->whereBetween('latitude', [$south, $north])
                ->whereBetween('longitude', [$west, $east]));
        });
    }

    /**
     * Narrow to a named place, matching whole words rather than the whole cell.
     *
     * `town` holds whatever the listing agent called the area, so it is a
     * locality and not a settlement: 89 distinct values across 180 Cork
     * properties. Exact equality therefore fails on anything a person would
     * actually type -- "Cork City" matched nothing at all while "Cork City
     * Centre" and "Cork City Suburbs" sat right there in the column.
     *
     * Matching on word boundaries picks those up, along with "Old Blackrock
     * Road" for Blackrock and "Lower Glanmire Road" for Glanmire, without
     * letting a bare substring drag "Ballinasloe" into a search for Ballina or
     * "Castletownshend" into one for Castletown.
     *
     * Spelled as LIKE clauses rather than a word-boundary regex because the
     * suite runs on SQLite, where Postgres's `~*` does not exist.
     */
    private function whereLocality(Builder $query, string $column, string $value): void
    {
        // Only ever a validated `location_type`, or the literal 'county'.
        $column = in_array($column, ['town', 'county'], true) ? $column : 'county';

        $term = mb_strtolower(trim($value));
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        $query->where(function (Builder $query) use ($column, $term, $like): void {
            $query->whereRaw("LOWER({$column}) = ?", [$term])
                ->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '\\'", [$like.' %'])
                ->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '\\'", ['% '.$like])
                ->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '\\'", ['% '.$like.' %']);
        });
    }
}
