<?php

namespace Modules\Properties\Imports;

use InvalidArgumentException;
use Modules\Properties\Imports\Providers\MockDaftProvider;
use Modules\Properties\Imports\Providers\MockMyHomeProvider;

class ListingProviderRegistry
{
    /** @param array<string, ListingProvider> $providers */
    public function __construct(private readonly array $providers) {}

    public static function make(): self
    {
        $providers = [new MockMyHomeProvider, new MockDaftProvider];

        return new self(collect($providers)->keyBy(fn (ListingProvider $provider): string => $provider->name())->all());
    }

    public function provider(string $name): ListingProvider
    {
        $provider = $this->providers[$name] ?? null;

        if ($provider === null) {
            throw new InvalidArgumentException("Unknown provider [{$name}].");
        }

        return $provider;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->providers);
    }
}
