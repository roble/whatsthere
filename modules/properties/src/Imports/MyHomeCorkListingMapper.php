<?php

namespace Modules\Properties\Imports;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Properties\PropertyKind;
use Modules\Properties\PropertyPreferences;

class MyHomeCorkListingMapper
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function map(array $payload): array
    {
        $listingId = $this->requiredString($payload, 'propertyId');
        $displayAddress = $this->requiredString($payload, 'displayAddress');
        $location = $this->location($payload, $displayAddress);
        $latitude = $this->coordinate($payload['lat'] ?? null);
        $longitude = $this->coordinate($payload['lon'] ?? null);

        if ($latitude === null || $longitude === null || ($latitude === 0.0 && $longitude === 0.0)) {
            throw new InvalidArgumentException("MyHome listing [{$listingId}] has no coordinates.");
        }

        $price = $this->priceInCents($payload['price'] ?? null);

        if ($price === null) {
            throw new InvalidArgumentException("MyHome listing [{$listingId}] has no valid asking price.");
        }

        $listedOn = today()->toDateString();

        return [
            'id' => $listingId,
            'url' => safe_http_url(filled($payload['url'] ?? null) ? (string) $payload['url'] : null),
            'title' => $displayAddress,
            'status' => 'active',
            'listed_on' => $listedOn,
            'last_seen_on' => $listedOn,
            'address' => $location['address'],
            'town' => $location['town'],
            'county' => $location['county'],
            'country' => 'IE',
            'eircode' => $this->normalizeEircode($payload['eircode'] ?? null),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'property_type' => PropertyKind::fromRaw(
                $payload['propertyType'] ?? null,
                $location['address'],
            ),
            'bedrooms' => $this->count($payload['beds'] ?? null),
            'bathrooms' => $this->count($payload['baths'] ?? null),
            'floor_area_sqm' => $this->floorArea($payload['sizeSqM'] ?? null),
            'ber_rating' => $this->berRating($payload['berRating'] ?? null),
            'description' => $this->description($payload),
            'media' => $this->media($payload),
            'prices' => [[
                'record_type' => 'asking_price',
                'amount' => $price,
                'currency' => 'EUR',
                'effective_date' => $listedOn,
            ]],
            'metadata' => [
                'source' => 'myhome.ie',
                'region' => Arr::get($payload, 'regionName', 'Cork'),
                'agent' => $this->agentName($payload),
                'size_display' => $payload['sizeDisplay'] ?? null,
                'raw_property_type' => $payload['propertyType'] ?? null,
                'features' => collect($payload['propertyFeatureTypes'] ?? [])
                    ->pluck('Name')
                    ->filter()
                    ->values()
                    ->all(),
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function location(array $payload, string $displayAddress): array
    {
        $town = filled($payload['localityName'] ?? null)
            ? trim((string) $payload['localityName'])
            : null;
        $county = filled($payload['regionName'] ?? null)
            ? $this->normalizeCounty((string) $payload['regionName'])
            : null;

        if ($town !== null && $county !== null) {
            return [
                'address' => $this->addressFromDisplay($displayAddress, $town, $county),
                'town' => $town,
                'county' => $county,
            ];
        }

        return $this->locationFromDisplayAddress($displayAddress);
    }

    private function addressFromDisplay(string $displayAddress, string $town, string $county): string
    {
        $parts = collect(explode(',', $displayAddress))
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->values();

        $townIndex = $parts->search(fn (string $part): bool => Str::lower($part) === Str::lower($town));

        if ($townIndex !== false && $townIndex > 0) {
            return $parts->slice(0, $townIndex)->join(', ');
        }

        $address = $parts
            ->reject(fn (string $part): bool => $this->isCountyPart($part) || Str::lower($part) === Str::lower($town))
            ->join(', ');

        return $address !== '' ? $address : $town;
    }

    /** @return array{address: string, town: string, county: string} */
    private function locationFromDisplayAddress(string $displayAddress): array
    {
        $parts = collect(explode(',', $displayAddress))
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->values();

        $countyIndex = $parts->search(fn (string $part): bool => $this->isCountyPart($part));

        if ($countyIndex === false) {
            $countyIndex = $parts->search(fn (string $part): bool => Str::lower($part) === 'cork');
        }

        if ($countyIndex === false || $countyIndex < 1) {
            throw new InvalidArgumentException("MyHome listing address [{$displayAddress}] does not contain a usable county.");
        }

        $county = $this->normalizeCounty($parts[$countyIndex]);
        $town = $parts[$countyIndex - 1];
        $address = $parts->slice(0, $countyIndex - 1)->join(', ');

        if ($address === '') {
            $address = $town;
        }

        return ['address' => $address, 'town' => $town, 'county' => $county];
    }

    private function isCountyPart(string $part): bool
    {
        return preg_match('/^co\.?\s*cork$/i', trim($part)) === 1;
    }

    private function normalizeCounty(string $county): string
    {
        return Str::title((string) Str::of($county)
            ->replaceMatches('/^co(\.+\s*|\s+)/i', '')
            ->replaceMatches('/[.\s]+$/', '')
            ->replaceMatches('/\s+city$/i', '')
            ->trim());
    }

    private function priceInCents(mixed $price): ?int
    {
        if (is_numeric($price)) {
            return ((int) $price) * 100;
        }

        if (! is_string($price) || preg_match('/^€\s*([\d,]+)$/', trim($price), $matches) !== 1) {
            return null;
        }

        return ((int) str_replace(',', '', $matches[1])) * 100;
    }

    private function count(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (! is_string($value) || preg_match('/\d+/', $value, $matches) !== 1) {
            return null;
        }

        return (int) $matches[0];
    }

    private function floorArea(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $area = (float) $value;

        return $area > 0 ? $area : null;
    }

    private function coordinate(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function berRating(mixed $value): ?string
    {
        $rating = Str::upper(trim((string) ($value ?? '')));

        if ($rating === '') {
            return null;
        }

        if (in_array($rating, PropertyPreferences::BER_RATINGS, true)) {
            return $rating;
        }

        if (preg_match('/^([A-G])(\d)?$/', $rating, $matches) === 1) {
            $normalized = $matches[1].($matches[2] ?? '1');

            return in_array($normalized, PropertyPreferences::BER_RATINGS, true) ? $normalized : null;
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function description(array $payload): ?string
    {
        $content = collect($payload['brochureContent'] ?? [])
            ->first(fn (mixed $section): bool => is_array($section) && ($section['ContentType'] ?? null) === 'Description');

        if (! is_array($content) || ! filled($content['Content'] ?? null)) {
            return null;
        }

        $description = trim(strip_tags((string) $content['Content']));

        return $description === '' ? null : $description;
    }

    /** @param array<string, mixed> $payload
     * @return list<array{url: string, alt_text: ?string}>
     */
    private function media(array $payload): array
    {
        $photos = collect($payload['photos'] ?? [])
            ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
            ->values();

        if ($photos->isEmpty() && filled($payload['mainPhoto'] ?? null)) {
            $photos = collect([(string) $payload['mainPhoto']]);
        }

        return $photos
            ->map(fn (string $url): ?string => safe_http_url($url))
            ->filter()
            ->take(12)
            ->map(fn (string $url): array => ['url' => $url, 'alt_text' => null])
            ->all();
    }

    /** @param array<string, mixed> $payload */
    private function agentName(array $payload): ?string
    {
        if (is_string($payload['agent'] ?? null) && $payload['agent'] !== '') {
            return $payload['agent'];
        }

        if (is_array($payload['agent'] ?? null) && filled($payload['agent']['groupName'] ?? null)) {
            return (string) $payload['agent']['groupName'];
        }

        return null;
    }

    private function normalizeEircode(mixed $eircode): ?string
    {
        if (! filled($eircode)) {
            return null;
        }

        return Str::upper((string) preg_replace('/\s+/', '', (string) $eircode));
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! filled($value)) {
            throw new InvalidArgumentException("MyHome listing is missing required field [{$key}].");
        }

        return (string) $value;
    }
}
