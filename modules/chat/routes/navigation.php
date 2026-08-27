<?php

use App\Facades\Navigation;
use App\Navigation\Section;

/*
|--------------------------------------------------------------------------
| Chat Module Navigation
|--------------------------------------------------------------------------
|
| Define Chat module navigation items here.
| These items will be loaded automatically when the module is enabled.
|
*/

Navigation::add('Chat', fn () => route('chat.index'), function (Section $section) {
    $section->attributes([
        'group' => 'main',
        'slug' => 'chat',
        'icon' => 'chat',
        'badge' => [
            'content' => 'New',
            'variant' => 'info',
        ],
    ]);
});
