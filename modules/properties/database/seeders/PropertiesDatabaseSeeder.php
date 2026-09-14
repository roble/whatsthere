<?php

namespace Modules\Properties\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Properties\Imports\ListingImportService;

class PropertiesDatabaseSeeder extends Seeder
{
    /**
     * Load every listing fixture in the repository.
     *
     * MyHome Cork lite covers current for-sale stock. Daft sold fixtures keep
     * the price history a portal search does not give you. The full
     * myhome-cork.json dump is gitignored — it is too large to seed on a
     * default 128M PHP box and must not re-enter git history.
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
            $provider = $this->providerFor($fixture);
            $import = $importer->import($provider, $fixture);

            $this->command?->getOutput()->writeln(sprintf(
                '  <fg=gray>%s</> (%s): %d/%d imported%s',
                basename($fixture),
                $provider,
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
    public function fixtures(): array
    {
        $fixtures = glob(base_path('modules/properties/database/fixtures/*.json')) ?: [];

        $fixtures = array_values(array_filter(
            $fixtures,
            static fn (string $path): bool => basename($path) !== 'myhome-cork.json',
        ));

        sort($fixtures);

        return $fixtures;
    }

    public function providerFor(string $fixture): string
    {
        return str_ends_with(basename($fixture), '-daft.json') ? 'daft' : 'myhome';
    }
}
