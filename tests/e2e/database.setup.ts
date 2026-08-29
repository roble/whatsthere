import { test as setup } from '@saucebase/laravel-playwright';

setup('setup the database', async ({ laravel }) => {
    // `migrate`, never `migrate:fresh`. The e2e suite runs against the same
    // database the dev app serves, so dropping tables here destroys whatever
    // the team was working on. Pending migrations are enough to get the schema
    // current; the seeders below are all firstOrCreate and safe to re-run.
    await laravel.artisan('migrate');
    await laravel.artisan('db:seed');
    await laravel.artisan('modules:seed');
});
