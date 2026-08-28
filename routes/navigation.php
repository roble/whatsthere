<?php

use App\Facades\Navigation;
use App\Navigation\Section;
use Illuminate\Support\Facades\Auth;

/*
|--------------------------------------------------------------------------
| Core Navigation
|--------------------------------------------------------------------------
|
| Define core application navigation items here.
| These items will be loaded automatically by the Navigation service.
|
*/

Navigation::add(
    'Discord',
    'https://discord.gg/hCajBky39t',
    function (Section $section) {
        $section->attributes([
            'group' => 'landing',
            'slug' => 'discord',
            'external' => true,
            'newPage' => true,
            'order' => 99,
            'class' => 'text-[#5865F2] hover:text-[#5865F2]/70',
        ]);
    }
);

Navigation::addWhen(
    fn () => Auth::check() && Auth::user()->isAdmin(),
    'Admin',
    fn () => route('filament.admin.pages.dashboard'),
    function (Section $section) {
        $section->attributes([
            'group' => 'secondary',
            'slug' => 'admin',
            'icon' => 'admin',
            'order' => 10,
            'external' => true,
            'newPage' => true,
            'class' => 'bg-yellow-500/10 text-yellow-600 hover:bg-yellow-500/20 hover:text-yellow-700 dark:hover:text-yellow-400',
        ]);
    }
);
