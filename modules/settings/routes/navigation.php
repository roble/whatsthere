<?php

use App\Facades\Navigation;
use App\Navigation\Section;

/*
|--------------------------------------------------------------------------
| Settings Module Navigation
|--------------------------------------------------------------------------
|
| Define Settings module navigation items here.
| These items will be loaded automatically when the module is enabled.
|
*/

// User menu - Settings
Navigation::add('Settings', fn () => route('settings.profile'), function (Section $section) {
    $section->attributes([
        'group' => 'user',
        'slug' => 'settings',
        'icon' => 'settings',
        'order' => 10,
    ]);
});

// Settings sidebar - Profile
Navigation::add('Profile', fn () => route('settings.profile'), function (Section $section) {
    $section->attributes([
        'group' => 'settings',
        'slug' => 'profile',
        'icon' => 'profile',
        'order' => 10,
    ]);
});
