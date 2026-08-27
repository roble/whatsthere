<?php

namespace Modules\Chat\Filament;

use App\Filament\ModulePlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class ChatPlugin implements Plugin
{
    use ModulePlugin;

    public function getModuleName(): string
    {
        return 'Chat';
    }

    public function getId(): string
    {
        return 'chat';
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
