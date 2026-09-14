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

        $locationColumn = match ($filters['location_type']) {
            'town' => 'town',
            'county' => 'county',
            default => throw new \InvalidArgumentException('Unsupported location type.'),
        };

        $query = Property::query()->with(['askingPrice', 'activeListing.media'])
            ->where('status', 'for_sale')->whereNotNull('latitude')->whereNotNull('longitude')
            ->where(fn (Builder $query) => $query
                ->where('latitude', '!=', 0)
                ->where('longitude', '!=', 0))
            ->whereHas('askingPrice', fn (Builder $query) => $query->where('currency', 'EUR'))
            ->whereRaw('LOWER('.$locationColumn.') = ?', [mb_strtolower($filters['location'])])
            ->where($price, '<=', $filters['max_price']);

        if ($filters['location_type'] === 'town' && $filters['county'] !== null) {
            $query->whereRaw('LOWER(county) = ?', [mb_strtolower($filters['county'])]);
        }
        if ($filters['property_type'] !== null) {
            $this->constrainType($query, $filters['property_type']);
        }
        if ($filters['property_type'] !== 'land' && $filters['min_bedrooms'] !== null) {
            $query->where('bedrooms', '>=', $filters['min_bedrooms']);
        }
        if ($filters['property_type'] !== 'land' && $filters['minimum_ber_rating'] !== null) {
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

        $cap = max(1, (int) config('properties.search_limit', 100));
        $full = max($cap, (int) config('properties.search_limit_full', 250));
        $limit = $total <= $full ? $total : $cap;
        if (($filters['sort'] ?? 'price') === 'price_per_sqm') {
            $rate = PropertyPriceRecord::query()->selectRaw('amount / nullif(properties.floor_area_sqm, 0)')
                ->whereColumn('property_id', 'properties.id')
                ->where('record_type', 'asking_price')->where('effective_date', '<=', today())
                ->orderByDesc('effective_date')->orderByDesc('id')->limit(1);

            $query->orderByRaw('case when properties.floor_area_sqm is null or properties.floor_area_sqm <= 0 then 1 else 0 end')
                ->orderBy($rate)
                ->orderBy('properties.id');
        } else {
            $query->orderBy($price)->orderBy('properties.id');
        }
        $properties = $query->limit($limit)->get();
        $markers = $properties->map($this->toMarker(...))->all();

        $markers = self::withHighlights(
            $markers,
            $filters['min_bedrooms'] ?? null,
            $filters['sort'] ?? 'price',
        );

        $lats = array_column($markers, 'lat');
        $lons = array_column($markers, 'lon');
        $defaultBbox = config('properties.default_bbox', ['-10.5', '51.35', '-7.4', '52.25']);

        return [
            'label' => 'Properties for sale in '.$filters['location'],
            'categoryKey' => 'property',
            'total' => $total,
            'markers' => $markers,
            'preferences' => $filters,
            'relaxed' => [],
            'bbox' => $markers === [] ? $defaultBbox : [
                (string) (min($lons) - 0.01), (string) (min($lats) - 0.01),
                (string) (max($lons) + 0.01), (string) (max($lats) + 0.01),
            ],
        ];
    }

    /**
     * Search, then drop leftover filters until something matches.
     *
     * Chat follow-ups keep the last type and bedroom count unless the model
     * sends null. That stacks until every listing is excluded. Widening here
     * is what makes "just housing under €200k" find homes after an apartment
     * search came up empty.
     *
     * @param  array<string, mixed>  $preferences
     * @return array<string, mixed>
     */
    public function searchOrWiden(array $preferences): array
    {
        $current = PropertyPreferences::validate($preferences);
        $relaxed = [];
        $view = $this->search($current);

        if (($view['total'] ?? 0) > 0) {
            $view['preferences'] = $current;
            $view['relaxed'] = [];

            return $view;
        }

        foreach (['property_type', 'min_bedrooms', 'minimum_ber_rating'] as $key) {
            if ($current[$key] === null) {
                continue;
            }

            $current[$key] = null;
            $relaxed[] = $key;
            $view = $this->search($current);

            if (($view['total'] ?? 0) > 0) {
                $view['preferences'] = $current;
                $view['relaxed'] = $relaxed;

                return $view;
            }
        }

        if ($current['location_type'] === 'town') {
            $current['location_type'] = 'county';
            $current['county'] = null;
            $relaxed[] = 'location_type';
            $view = $this->search($current);

            if (($view['total'] ?? 0) > 0) {
                $view['preferences'] = $current;
                $view['relaxed'] = $relaxed;

                return $view;
            }
        }

        $view['preferences'] = $current;
        $view['relaxed'] = $relaxed;

        return $view;
    }

    /**
     * One for-sale listing, as the map and the assistant already see it.
     *
     * Used when the browser names a selected pin by id: the facts must come
     * from the database, not from fields the client can rewrite.
     *
     * @return array<string, mixed>|null
     */
    public function listing(int $id): ?array
    {
        $property = Property::query()
            ->with(['askingPrice', 'activeListing.media'])
            ->where('id', $id)
            ->where('status', 'for_sale')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where(fn (Builder $query) => $query
                ->where('latitude', '!=', 0)
                ->where('longitude', '!=', 0))
            ->first();

        if ($property === null || $property->askingPrice === null) {
            return null;
        }

        return $this->toMarker($property);
    }

    /**
     * A map view small enough to store in chat tool history without blowing
     * token limits. Descriptions and full photo galleries stay on the server.
     *
     * @param  array<string, mixed>  $view
     */
    public function compactToolResponse(array $view): string
    {
        $markers = $view['markers'] ?? [];
        $limit = max(1, (int) config('properties.ai_tool_marker_limit', 30));
        $sample = array_slice($markers, 0, $limit);

        $relaxed = $view['relaxed'] ?? [];
        $note = 'Quote each marker\'s price and rate fields exactly — they are already in euro. asking_price and price_per_sqm are integer cents for the map and must never be spoken or converted.';

        if ($relaxed !== []) {
            $note .= ' Filters were widened to find matches (dropped: '.implode(', ', $relaxed).'). Say what you had to relax, then advise from these listings.';
        }

        return json_encode([
            'label' => $view['label'],
            'categoryKey' => $view['categoryKey'] ?? 'property',
            'total' => $view['total'] ?? count($markers),
            'shown' => count($sample),
            'bbox' => $view['bbox'],
            'markers' => array_map(self::slimMarker(...), $sample),
            'relaxed' => $relaxed,
            'note' => $note,
        ], JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $marker
     *  @return array<string, mixed> */
    public static function slimMarker(array $marker): array
    {
        $images = $marker['images'] ?? [];
        $firstImage = is_array($images) && $images !== [] && is_string($images[0])
            ? [$images[0]]
            : [];

        $slim = [
            'id' => $marker['id'] ?? null,
            'name' => $marker['name'] ?? null,
            'address' => $marker['address'] ?? null,
            'town' => $marker['town'] ?? null,
            'county' => $marker['county'] ?? null,
            'lat' => $marker['lat'] ?? null,
            'lon' => $marker['lon'] ?? null,
            'asking_price' => $marker['asking_price'] ?? null,
            'price' => self::euroLabel($marker['asking_price'] ?? null),
            'currency' => $marker['currency'] ?? 'EUR',
            'bedrooms' => $marker['bedrooms'] ?? null,
            'bathrooms' => $marker['bathrooms'] ?? null,
            'property_type' => $marker['property_type'] ?? null,
            'ber_rating' => $marker['ber_rating'] ?? null,
            'floor_area_sqm' => $marker['floor_area_sqm'] ?? null,
            'plot_area_sqm' => $marker['plot_area_sqm'] ?? null,
            'size_label' => $marker['size_label'] ?? null,
            'price_per_sqm' => $marker['price_per_sqm'] ?? null,
            'rate' => self::rateLabel($marker['price_per_sqm'] ?? null),
            'highlight' => $marker['highlight'] ?? 'typical',
            'categoryKey' => $marker['categoryKey'] ?? 'property',
        ];

        $safeImage = is_string($firstImage[0] ?? null) ? safe_listing_image_url($firstImage[0]) : null;

        if ($safeImage !== null) {
            $slim['images'] = [$safeImage];
        }

        return array_filter(
            $slim,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * A price the assistant can copy without converting cents.
     */
    public static function euroLabel(mixed $cents): ?string
    {
        if (! is_numeric($cents) || (int) $cents <= 0) {
            return null;
        }

        return '€'.number_format(((int) $cents) / 100, 0);
    }

    public static function rateLabel(mixed $centsPerSqm): ?string
    {
        $label = self::euroLabel($centsPerSqm);

        return $label === null ? null : $label.'/m2';
    }

    /** @param  list<array<string, mixed>>  $markers
     *  @return list<array<string, mixed>> */
    private static function withHighlights(array $markers, ?int $minBedrooms, string $sort = 'price'): array
    {
        $metric = $sort === 'price_per_sqm' ? 'price_per_sqm' : 'asking_price';
        $values = array_values(array_filter(
            array_column($markers, $metric),
            fn (mixed $value): bool => is_numeric($value) && (int) $value > 0,
        ));
        sort($values);
        $count = count($values);
        $spread = $count >= 3 && ($values[0] ?? 0) !== ($values[$count - 1] ?? 0);
        $low = $spread ? $values[(int) floor(($count - 1) * 0.25)] : null;
        $high = $spread ? $values[(int) ceil(($count - 1) * 0.75)] : null;

        return array_map(static function (array $marker) use ($low, $high, $minBedrooms, $metric): array {
            $value = $marker[$metric] ?? null;

            if ($minBedrooms !== null
                && ($marker['property_type'] ?? null) !== 'land'
                && ($marker['bedrooms'] ?? null) === $minBedrooms) {
                $marker['highlight'] = 'match';
            } elseif ($low !== null && is_numeric($value) && (int) $value > 0 && (int) $value <= $low) {
                $marker['highlight'] = 'value';
            } elseif ($high !== null && is_numeric($value) && (int) $value >= $high) {
                $marker['highlight'] = 'premium';
            }

            return $marker;
        }, $markers);
    }

    /** @return array<string, mixed> */
    private function toMarker(Property $property): array
    {
        $listing = $property->activeListing;
        $metadata = $listing?->metadata ?? [];
        $images = array_values(array_filter(
            array_map(
                fn (mixed $url): ?string => is_string($url) ? safe_listing_image_url($url) : null,
                $listing?->media->pluck('url')->all() ?? [],
            ),
        ));
        $kind = PropertyKind::resolve(
            $property->property_type,
            $metadata['raw_property_type'] ?? null,
            $property->address,
        );
        $plot = PropertyKind::plotSize(
            $property->floor_area_sqm,
            isset($metadata['size_display']) ? (string) $metadata['size_display'] : null,
            $property->address,
            $property->description,
            $kind,
        );
        $area = $kind === 'land'
            ? ($plot['sqm'] ?? $property->floor_area_sqm)
            : $property->floor_area_sqm;
        $pricePerSqm = $area !== null && (float) $area > 0
            ? (int) round($property->askingPrice->amount / (float) $area)
            : null;

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
            'bedrooms' => $kind === 'land' ? null : $property->bedrooms,
            'property_type' => $kind,
            'ber_rating' => $kind === 'land' ? null : $property->ber_rating,
            'bathrooms' => $kind === 'land' ? null : $property->bathrooms,
            'floor_area_sqm' => $kind === 'land' ? null : $property->floor_area_sqm,
            'plot_area_sqm' => $plot['sqm'] ?? null,
            'size_label' => $plot['label'] ?? null,
            'price_per_sqm' => $pricePerSqm,
            'description' => $property->description,
            'url' => safe_http_url($listing?->url),
            'agent' => $metadata['agent'] ?? null,
            'images' => $images,
            'highlight' => 'typical',
            'categoryKey' => 'property',
            'details' => [
                'address' => "{$property->address}, {$property->town}, {$property->county}",
                'description' => $property->description,
                'url' => safe_http_url($listing?->url),
                'agent' => $metadata['agent'] ?? null,
            ],
        ];
    }

    private function constrainType(Builder $query, string $type): void
    {
        if ($type !== 'land') {
            $query->where('property_type', $type);

            return;
        }

        $query->where(function (Builder $inner): void {
            $inner->where('property_type', 'land')
                ->orWhereRaw('address like ?', ['Site%'])
                ->orWhereRaw('address like ?', ['site%'])
                ->orWhereRaw('address like ?', ['Plot%'])
                ->orWhereRaw('address like ?', ['%Site @%'])
                ->orWhereRaw('address like ?', ['%site @%'])
                ->orWhereHas('activeListing', function (Builder $listing): void {
                    $listing->where(function (Builder $meta): void {
                        foreach (['Site', 'site', 'Plot', 'plot'] as $needle) {
                            $meta->orWhere('metadata->raw_property_type', 'like', '%'.$needle.'%');
                        }
                    });
                });
        });
    }
}
