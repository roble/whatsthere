<?php

namespace Modules\Properties\Console\Commands;

use Illuminate\Console\Command;
use Modules\Properties\Imports\ListingImportService;
use Modules\Properties\Imports\ListingProviderRegistry;

class ImportDataCommand extends Command
{
    protected $signature = 'data:import
                            {--provider= : Listing provider: daft}
                            {--file= : Optional JSON source. Defaults to the provider fixture.}';

    protected $description = 'Import property listings and price records from a Daft response or fixture.';

    public function handle(ListingImportService $importer, ListingProviderRegistry $providers): int
    {
        $provider = $this->option('provider');

        if (! is_string($provider) || $provider === '') {
            $this->error('The --provider option is required. Available providers: '.implode(', ', $providers->names()));

            return self::FAILURE;
        }

        if (! in_array($provider, $providers->names(), true)) {
            $this->error("Unknown provider [{$provider}]. Available providers: ".implode(', ', $providers->names()));

            return self::FAILURE;
        }

        $sourcePath = $this->option('file');
        $import = $importer->import($provider, is_string($sourcePath) && $sourcePath !== '' ? $sourcePath : null);

        $this->newLine();
        $this->info("Import {$import->status}: {$import->imported_records}/{$import->total_records} records imported.");

        if ($import->failed_records > 0) {
            $this->error("{$import->failed_records} record(s) failed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
