<?php

namespace Modules\Properties\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Properties\Imports\ListingImportService;

class PropertySeeder extends Seeder
{
    /**
     * Load every listing fixture in the repository.
     *
     * The fixtures are real scraped listings, so there is nothing to invent
     * here: seeding is the same import a developer would run by hand, over
     * every file at once. Inventing properties instead would put addresses
     * that do not exist in front of anyone looking at the map.
     *
     * The importer is idempotent, so re-seeding updates rows rather than
     * duplicating them, and a malformed listing is counted and skipped rather
     * than taking the rest of its file down with it.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $importer = app(ListingImportService::class);

        foreach ($this->fixtures() as $fixture) {
            $import = $importer->import('daft', $fixture);

            $this->command?->getOutput()->writeln(sprintf(
                '  <fg=gray>%s</>: %d/%d imported%s',
                basename($fixture),
                $import->imported_records,
                $import->total_records,
                $import->failed_records > 0 ? ", {$import->failed_records} skipped" : '',
            ));
        }
    }

    /**
     * Every fixture to import, in a stable order.
     *
     * Sorted so a re-seed replays the files the same way each time: the last
     * price record for a property wins, and that should not depend on how the
     * filesystem happened to order the directory.
     *
     * @return list<string>
     */
    private function fixtures(): array
    {
        $fixtures = glob(base_path('modules/properties/database/fixtures/*.json')) ?: [];

        sort($fixtures);

        return $fixtures;
    }
}
