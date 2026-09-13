<?php

namespace Modules\Properties\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Properties\Imports\ListingImportService;

class PropertiesDatabaseSeeder extends Seeder
{
    /**
     * Load every MyHome Cork listing fixture in the repository.
     *
     * The fixtures are real scraped listings, so seeding is the same import a
     * developer would run by hand, over every file at once. Inventing properties
     * instead would put addresses that do not exist in front of anyone looking
     * at the map.
     *
     * The importer is idempotent, so re-seeding updates rows rather than
     * duplicating them, and a malformed listing is counted and skipped rather
     * than taking the rest of its file down with it. That makes it safe to run
     * on every deploy.
     *
     * Deliberately not guarded against production. The listings are real, so
     * there is nothing here that must be kept off a live site, and a guard
     * would only make a deploy step succeed while doing nothing. `db:seed`
     * already demands `--force` outside local environments.
     */
    public function run(): void
    {
        $importer = app(ListingImportService::class);

        foreach ($this->fixtures() as $fixture) {
            $import = $importer->import('myhome', $fixture);

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
     * @return list<string>
     */
    private function fixtures(): array
    {
        return [base_path('modules/properties/database/fixtures/myhome-cork.json')];
    }
}
