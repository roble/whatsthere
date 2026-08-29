<?php

namespace Tests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
    }

    /** @return User&Authenticatable */
    protected function createUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::USER);

        return $user;
    }
}
