<?php

namespace Modules\Properties\Imports;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Properties\Models\DataImport;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\PropertyListing;
use Throwable;

class ListingImportService
{
    private const DEFAULT_IMAGE = '/modules/properties/images/cork-home-exterior.png';

    public function __construct(private readonly ListingProviderRegistry $providers) {}

    public function import(string $providerName, ?string $sourcePath = null): DataImport
    {
        $provider = $this->providers->provider($providerName);
        $sourcePath ??= $provider->defaultSourcePath();

        if (! is_readable($sourcePath)) {
            throw new \InvalidArgumentException("The import source [{$sourcePath}] is not readable.");
        }

        $import = DataImport::query()->create([
            'provider' => $provider->name(),
            'source_path' => $sourcePath,
            'checksum' => hash_file('sha256', $sourcePath),
            'status' => 'running',
            'started_at' => now(),
        ]);

        $totalRecords = 0;
        $importedRecords = 0;
        $errors = [];

        foreach ($provider->records($sourcePath) as $index => $record) {
            $totalRecords++;

            try {
                DB::transaction(function () use ($provider, $record): void {
                    $this->importRecord($provider, $record);
                });
                $importedRecords++;
            } catch (Throwable $exception) {
                report($exception);
                $errors[] = "Record {$index}: {$exception->getMessage()}";
            }
        }

        $import->update([
            'status' => $errors === [] ? 'completed' : 'failed',
            'total_records' => $totalRecords,
            'imported_records' => $importedRecords,
            'failed_records' => count($errors),
            'summary' => $errors === [] ? null : ['errors' => array_slice($errors, 0, 10)],
            'completed_at' => now(),
        ]);

        return $import->refresh();
    }

    /** @param array<string, mixed> $record */
    private function importRecord(ListingProvider $provider, array $record): void
    {
        foreach (['id', 'address', 'town', 'county', 'status'] as $field) {
            if (! filled($record[$field] ?? null)) {
                throw new \InvalidArgumentException("Missing required field [{$field}].");
            }
        }

        $property = $this->findProperty($record) ?? new Property([
            'reference' => $provider->name().':'.$record['id'],
        ]);

        $property->fill([
            'address' => $record['address'],
            'normalized_address' => $this->normalizeAddress($record['address']),
            'town' => $record['town'],
            'county' => $record['county'],
            'country' => $record['country'] ?? 'IE',
            'eircode' => $this->normalizeEircode($record['eircode'] ?? null),
            'latitude' => $record['latitude'] ?? null,
            'longitude' => $record['longitude'] ?? null,
            'property_type' => $record['property_type'] ?? 'other',
            'bedrooms' => $record['bedrooms'] ?? null,
            'bathrooms' => $record['bathrooms'] ?? null,
            'floor_area_sqm' => $record['floor_area_sqm'] ?? null,
            'ber_rating' => $record['ber_rating'] ?? null,
            'description' => $record['description'] ?? null,
            'status' => $this->propertyStatus($record['status']),
        ]);
        $property->save();

        $listing = PropertyListing::query()->updateOrCreate(
            ['provider' => $provider->name(), 'provider_listing_id' => (string) $record['id']],
            [
                'property_id' => $property->id,
                'url' => $record['url'] ?? null,
                'title' => $record['title'] ?? $property->address,
                'status' => $record['status'],
                'listed_on' => $record['listed_on'] ?? null,
                'last_seen_on' => $record['last_seen_on'] ?? today(),
                'metadata' => Arr::wrap($record['metadata'] ?? []),
            ],
        );

        $this->replaceMedia($listing, $record['media'] ?? []);
        $this->upsertPrices($listing, $record['prices'] ?? []);
    }

    /** @param array<string, mixed> $record */
    private function findProperty(array $record): ?Property
    {
        $eircode = $this->normalizeEircode($record['eircode'] ?? null);

        if ($eircode !== null) {
            $property = Property::query()->where('eircode', $eircode)->first();

            if ($property !== null) {
                return $property;
            }
        }

        return Property::query()
            ->where('normalized_address', $this->normalizeAddress($record['address']))
            ->whereRaw('LOWER(town) = ?', [Str::lower($record['town'])])
            ->whereRaw('LOWER(county) = ?', [Str::lower($record['county'])])
            ->first();
    }

    /** @param list<array<string, mixed>> $media */
    private function replaceMedia(PropertyListing $listing, array $media): void
    {
        $listing->media()->delete();

        $media = $media === [] ? [['url' => self::DEFAULT_IMAGE]] : $media;

        foreach ($media as $position => $item) {
            if (! filled($item['url'] ?? null)) {
                continue;
            }

            $listing->media()->create([
                'media_type' => $item['media_type'] ?? 'image',
                'url' => $item['url'],
                'alt_text' => $item['alt_text'] ?? null,
                'position' => $position,
            ]);
        }
    }

    /** @param list<array<string, mixed>> $prices */
    private function upsertPrices(PropertyListing $listing, array $prices): void
    {
        foreach ($prices as $price) {
            if (! in_array($price['record_type'] ?? null, ['asking_price', 'sale'], true)) {
                throw new \InvalidArgumentException('Each price must have an asking_price or sale record type.');
            }

            if (! isset($price['amount'], $price['effective_date'])) {
                throw new \InvalidArgumentException('Each price must have an amount and effective date.');
            }

            $listing->priceRecords()->updateOrCreate(
                [
                    'record_type' => $price['record_type'],
                    'effective_date' => $price['effective_date'],
                ],
                [
                    'property_id' => $listing->property_id,
                    'amount' => $price['amount'],
                    'currency' => $price['currency'] ?? 'EUR',
                    'source_url' => $price['source_url'] ?? $listing->url,
                    'source_reference' => $price['source_reference'] ?? $listing->provider.':'.$listing->provider_listing_id.':'.$price['record_type'].':'.$price['effective_date'],
                ],
            );
        }
    }

    private function normalizeAddress(string $address): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(Str::lower($address))));
    }

    private function normalizeEircode(mixed $eircode): ?string
    {
        if (! filled($eircode)) {
            return null;
        }

        return Str::upper((string) preg_replace('/\s+/', '', (string) $eircode));
    }

    private function propertyStatus(string $listingStatus): string
    {
        return match ($listingStatus) {
            'active' => 'for_sale',
            'sold', 'withdrawn' => $listingStatus,
            default => throw new \InvalidArgumentException("Unknown listing status [{$listingStatus}]."),
        };
    }
}
