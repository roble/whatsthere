<?php

namespace Modules\Properties\Imports\Providers;

use InvalidArgumentException;
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

            // Mapping happens inside this generator, so an unmappable listing
            // would otherwise throw through the importer's foreach and abandon
            // every record after it. A real scrape always holds a few of them,
            // so one is reported and skipped rather than ending the import.
            try {
                yield array_key_exists('soldPrice', $record)
                    ? $mapper->mapSold($record)
                    : $mapper->map($record);
            } catch (InvalidArgumentException $exception) {
                yield ['unmappable' => $exception->getMessage()];
            }
        }
    }

    public function name(): string
    {
        return 'daft';
    }

    /**
     * One real fixture, for an import run without an explicit `--file`.
     *
     * The whole folder is what `PropertySeeder` loads; this is only the single
     * file a bare `data:import` reaches for.
     */
    public function defaultSourcePath(): string
    {
        return base_path('modules/properties/database/fixtures/buy-0001-daft.json');
    }
}
