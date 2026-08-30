<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Empty rather than seeded with today's rates: a wrong price quietly
        // reported as fact is worse than no price at all, and the insights page
        // says so in as many words when nothing is set.
        if (! $this->migrator->exists('chat.model_pricing')) {
            $this->migrator->add('chat.model_pricing', []);
        }
    }
};
