<?php

namespace Modules\Properties\Imports;

use JsonException;
use RuntimeException;

abstract class JsonListingProvider implements ListingProvider
{
    /** @return iterable<array<string, mixed>> */
    public function records(string $sourcePath): iterable
    {
        foreach ($this->decodedJson($sourcePath) as $record) {
            if (! is_array($record)) {
                throw new RuntimeException("The import source [{$sourcePath}] contains an invalid record.");
            }

            yield $record;
        }
    }

    /** @return array<mixed> */
    protected function decodedJson(string $sourcePath): array
    {
        $contents = file_get_contents($sourcePath);

        if ($contents === false) {
            throw new RuntimeException("Unable to read import source [{$sourcePath}].");
        }

        try {
            $records = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("The import source [{$sourcePath}] is not valid JSON.", previous: $exception);
        }

        if (! is_array($records)) {
            throw new RuntimeException("The import source [{$sourcePath}] must contain a JSON object or array.");
        }

        return $records;
    }
}
