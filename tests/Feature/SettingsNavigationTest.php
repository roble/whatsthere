<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Admin\Pages\GeneralSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_pages_do_not_render_duplicate_sub_navigation(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::ADMIN);

        $this->actingAs($admin);

        $page = app(GeneralSettings::class);

        $this->assertSame([], $page->getSubNavigation());
    }
}
