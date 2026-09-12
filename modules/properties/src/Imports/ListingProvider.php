<?php

namespace Modules\Properties\Imports;

interface ListingProvider
{
    public function name(): string;

    public function defaultSourcePath(): string;

    /** @return iterable<array<string, mixed>> */
    public function records(string $sourcePath): iterable;
}
