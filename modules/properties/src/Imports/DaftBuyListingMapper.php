<?php

namespace Modules\Properties\Imports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DaftBuyListingMapper
{
    private const IRISH_COUNTIES = [
        'antrim', 'armagh', 'carlow', 'cavan', 'clare', 'cork', 'derry', 'donegal', 'down', 'dublin',
        'fermanagh', 'galway', 'kerry', 'kildare', 'kilkenny', 'laois', 'leitrim', 'limerick', 'longford',
        'louth', 'mayo', 'meath', 'monaghan', 'offaly', 'roscommon', 'sligo', 'tipperary', 'tyrone',
        'waterford', 'westmeath', 'wexford', 'wicklow',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function map(array $payload): array
    {
        $title = $this->requiredString($payload, 'title');
        $location = $this->locationFromTitle($title);
        $listingId = $this->requiredString($payload, 'id');
        $coordinates = Arr::get($payload, 'point.coordinates');

        if (! is_array($coordinates) || count($coordinates) !== 2) {
            throw new InvalidArgumentException("Daft listing [{$listingId}] has no valid point coordinates.");
        }

        $price = $this->priceInCents($payload['price'] ?? null);
        $publishedOn = $this->dateFromMilliseconds($payload['publishDate'] ?? null);

        return [
            'id' => $listingId,
            'url' => $this->listingUrl($payload['seoFriendlyPath'] ?? null),
            'title' => $title,
            'status' => $this->listingStatus($payload['state'] ?? null),
            'listed_on' => $publishedOn,
            'last_seen_on' => today()->toDateString(),
            'address' => $location['address'],
            'town' => $location['town'],
            'county' => $location['county'],
            'country' => ($payload['isInRepublicOfIreland'] ?? true) ? 'IE' : 'GB',
            'eircode' => $location['eircode'],
            'latitude' => $coordinates[1],
            'longitude' => $coordinates[0],
            'property_type' => $this->propertyType($payload),
            'bedrooms' => $this->numberFromLabel($payload['numBedrooms'] ?? null),
            'bathrooms' => $this->numberFromLabel($payload['numBathrooms'] ?? null),
            'floor_area_sqm' => Arr::get($payload, 'floorArea.unit') === 'METRES_SQUARED'
                ? Arr::get($payload, 'floorArea.value')
                : null,
            'ber_rating' => Arr::get($payload, 'ber.rating'),
            'description' => null,
            'media' => $this->mediaFromPayload($payload),
            'prices' => $price === null || $publishedOn === null ? [] : [[
                'record_type' => 'asking_price',
                'amount' => $price,
                'currency' => 'EUR',
                'effective_date' => $publishedOn,
            ]],
            'metadata' => [
                'source_state' => $payload['state'] ?? null,
                'sale_type' => $payload['saleType'] ?? [],
                'sections' => $payload['sections'] ?? [],
                'daft_shortcode' => $payload['daftShortcode'] ?? null,
                'raw_property_type' => $payload['propertyType'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function mapSold(array $payload): array
    {
        $title = $this->requiredString($payload, 'title');
        $location = $this->locationFromTitle($title);
        $listingId = $this->requiredString($payload, 'id');
        $soldPrice = $this->priceInCents($payload['soldPrice'] ?? null);
        $soldDate = $this->dateFromDayMonthYear($payload['soldDate'] ?? null);

        if ($soldPrice === null || $soldDate === null) {
            throw new InvalidArgumentException("Daft sold listing [{$listingId}] must include a valid sold price and sold date.");
        }

        return [
            'id' => $listingId,
            'url' => $this->listingUrl($payload['seoFriendlyPath'] ?? null),
            'title' => $title,
            'status' => 'sold',
            'listed_on' => null,
            'last_seen_on' => today()->toDateString(),
            'address' => $location['address'],
            'town' => $location['town'],
            'county' => $location['county'],
            'country' => 'IE',
            'eircode' => $location['eircode'],
            'latitude' => null,
            'longitude' => null,
            'property_type' => $this->propertyType($payload),
            'bedrooms' => $this->numberFromLabel($payload['numBedrooms'] ?? null),
            'bathrooms' => $this->numberFromLabel($payload['numBathrooms'] ?? null),
            'floor_area_sqm' => null,
            'ber_rating' => Arr::get($payload, 'ber.rating'),
            'description' => null,
            'media' => $this->mediaFromPayload($payload),
            'prices' => [[
                'record_type' => 'sale',
                'amount' => $soldPrice,
                'currency' => 'EUR',
                'effective_date' => $soldDate,
            ]],
            'metadata' => [
                'source_state' => $payload['state'] ?? null,
                'sections' => $payload['sections'] ?? [],
                'daft_shortcode' => $payload['daftShortcode'] ?? null,
                'raw_property_type' => $payload['propertyType'] ?? null,
                'asking_price_display' => $payload['price'] ?? null,
            ],
        ];
    }

    /** @return array{address: string, town: string, county: string, eircode: string|null} */
    private function locationFromTitle(string $title): array
    {
        $parts = collect(explode(',', $title))
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->values();
        $eircode = $parts->last(fn (string $part): bool => preg_match('/^[A-Z]\d{2}\s?[A-Z0-9]{4}$/i', $part) === 1);
        $countyIndex = $parts->search(fn (string $part): bool => in_array($this->countyName($part), self::IRISH_COUNTIES, true));

        if ($countyIndex === false || $countyIndex < 1) {
            throw new InvalidArgumentException("Daft listing title [{$title}] does not contain a usable county.");
        }

        $county = Str::title($this->countyName($parts[$countyIndex]));
        $town = $parts[$countyIndex - 1];
        $address = $parts->slice(0, $countyIndex - 1)->join(', ');

        if ($address === '') {
            $address = $town;
        }

        return ['address' => $address, 'town' => $town, 'county' => $county, 'eircode' => $eircode];
    }

    /** @param array<string, mixed> $payload */
    private function propertyType(array $payload): string
    {
        $sections = collect($payload['sections'] ?? [])->map(fn (mixed $section): string => Str::lower((string) $section));

        if ($sections->contains('apartment')) {
            return 'apartment';
        }

        if ($sections->contains('house')) {
            return 'house';
        }

        return Str::lower((string) ($payload['propertyType'] ?? 'other'));
    }

    private function listingStatus(mixed $state): string
    {
        return match (Str::upper((string) $state)) {
            'PUBLISHED', 'SALE_AGREED' => 'active',
            'SOLD' => 'sold',
            default => 'withdrawn',
        };
    }

    private function listingUrl(mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        return 'https://www.daft.ie/'.ltrim($path, '/');
    }

    private function priceInCents(mixed $price): ?int
    {
        if (! is_string($price) || preg_match('/^€\s*([\d,]+)$/', trim($price), $matches) !== 1) {
            return null;
        }

        return ((int) str_replace(',', '', $matches[1])) * 100;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{url: string, alt_text: string}>
     */
    private function mediaFromPayload(array $payload): array
    {
        return collect(Arr::get($payload, 'media.images', []))
            ->filter(fn (mixed $image): bool => is_array($image) && filled($image['size720x480'] ?? null))
            ->values()
            ->map(fn (array $image): array => [
                'url' => $image['size720x480'],
                'alt_text' => collect($image['imageLabels'] ?? [])
                    ->pluck('label')
                    ->filter()
                    ->join(', '),
            ])
            ->all();
    }

    private function numberFromLabel(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/\d+/', $value, $matches) !== 1) {
            return null;
        }

        return (int) $matches[0];
    }

    private function dateFromMilliseconds(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC(intdiv((int) $value, 1000))->toDateString();
    }

    private function dateFromDayMonthYear(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!d/m/Y', $value, 'UTC')->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function countyName(string $part): string
    {
        return Str::lower((string) Str::of($part)
            ->replaceMatches('/^co\.?\s+/i', '')
            ->replaceMatches('/\s+city$/i', '')
            ->trim());
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! filled($value)) {
            throw new InvalidArgumentException("Daft listing is missing required field [{$key}].");
        }

        return (string) $value;
    }
}
