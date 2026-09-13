<?php

namespace Modules\Properties\Imports\Providers;

use InvalidArgumentException;
use Modules\Properties\Imports\JsonListingProvider;
use Modules\Properties\Imports\MyHomeCorkListingMapper;

class MockMyHomeProvider extends JsonListingProvider
{
    /** @return iterable<array<string, mixed>> */
    public function records(string $sourcePath): iterable
    {
        $payload = $this->decodedJson($sourcePath);

        if (array_is_list($payload)) {
            yield from parent::records($sourcePath);

            return;
        }

        $properties = $payload['properties'] ?? null;

        if (! is_array($properties)) {
            throw new \RuntimeException("The MyHome import source [{$sourcePath}] must contain a properties array.");
        }

        $mapper = new MyHomeCorkListingMapper;

        foreach ($properties as $property) {
            if (! is_array($property)) {
                throw new \RuntimeException("The MyHome import source [{$sourcePath}] contains an invalid property.");
            }

            try {
                yield $mapper->map($property);
            } catch (InvalidArgumentException $exception) {
                yield ['unmappable' => $exception->getMessage()];
            }
        }
    }

    public function name(): string
    {
        return 'myhome';
    }

    public function defaultSourcePath(): string
    {
        return base_path('modules/properties/database/fixtures/myhome-cork.json');
    }
}
