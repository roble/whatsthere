<?php

namespace Tests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected $seed = true;

    /**
     * Pin the test database before the application boots.
     *
     * PHPUnit's <env> entries reach putenv() and $_ENV, but Laravel's env
     * repository asks $_SERVER first -- and docker-compose.yml puts a real
     * DB_CONNECTION=mysql there. Without this, running the suite inside a
     * container sends RefreshDatabase at the development database and drops
     * every table in it.
     */
    protected function setUp(): void
    {
        $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_SERVER['DB_DATABASE'] = ':memory:';

        parent::setUp();

        // No PHP test should need `npm run build` to have run first. Blade
        // layouts that call @vite -- Filament's admin panel among them -- read
        // public/build/manifest.json, which is gitignored and absent in the
        // `phpunit-raw` CI job, so a page test that renders one fails there and
        // passes locally only because the Vite dev server is up.
        $this->withoutVite();

        // Inertia server-renders the landing page, which with a hot dev server
        // means a real POST to Vite on :5173 -- so the suite behaves one way
        // with `npm run dev` running and another without it, and CI exercises
        // the path not at all. Answering it here makes it the same everywhere.
        Http::fake(['*__inertia_ssr*' => Http::response(['head' => [], 'body' => ''])]);

        // Nothing else may leave the process. Laravel\Ai, Nominatim and
        // Overpass all go through this client, so a test that forgets its
        // Http::fake() would otherwise reach the real service -- and with a
        // live OPENAI_API_KEY loaded, spend money and still pass.
        Http::preventStrayRequests();
    }

    /** @return User&Authenticatable */
    protected function createUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::USER);

        return $user;
    }
}
