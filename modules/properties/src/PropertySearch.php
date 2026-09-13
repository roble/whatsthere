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
            ->whereRaw('LOWER('.$filters['location_type'].') = ?', [mb_strtolower($filters['location'])])
            ->where($price, '<=', $filters['max_price']);

        if ($filters['location_type'] === 'town' && $filters['county'] !== null) {
            $query->whereRaw('LOWER(county) = ?', [mb_strtolower($filters['county'])]);
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
}
