<?php

namespace Modules\Properties\Imports\Providers;

use Modules\Properties\Imports\DaftBuyListingMapper;
use Modules\Properties\Imports\JsonListingProvider;

class MockDaftProvider extends JsonListingProvider
{
    /** @return iterable<array<string, mixed>> */
    public function records(string $sourcePath): iterable
    {
        $payload = $this->decodedJson($sourcePath);

        if (array_is_list($payload)) {
            yield from parent::records($sourcePath);

            return;
        }

        $listings = $payload['listings'] ?? null;

        if (! is_array($listings)) {
            throw new \RuntimeException("The Daft import source [{$sourcePath}] must contain a listings array.");
        }

        $mapper = new DaftBuyListingMapper;

        foreach ($listings as $listing) {
            if (! is_array($listing) || ! is_array($listing['listing'] ?? null)) {
                throw new \RuntimeException("The Daft import source [{$sourcePath}] contains an invalid listing.");
            }

            $record = $listing['listing'];

            yield array_key_exists('soldPrice', $record)
                ? $mapper->mapSold($record)
                : $mapper->map($record);
        }
    }

    public function name(): string
    {
        return 'daft';
    }

    public function defaultSourcePath(): string
    {
        return base_path('modules/properties/database/fixtures/daft.json');
    }
}
