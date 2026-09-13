<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Home Search Area
    |--------------------------------------------------------------------------
    |
    | Where a brand new property conversation starts looking before the visitor
    | has said anything. The map is never allowed to open on an empty world, so
    | there has to be somewhere to open on.
    |
    | This is a starting point, not a restriction: the visitor and the assistant
    | can search anywhere the database holds, and nothing downstream filters on
    | it. Point it at a new area by changing these two values.
    |
    */

    'home' => [
        'location' => env('PROPERTIES_HOME_LOCATION', 'Cork'),
        'location_type' => env('PROPERTIES_HOME_LOCATION_TYPE', 'county'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Widest Asking Price
    |--------------------------------------------------------------------------
    |
    | The maximum asking price, in euro cents, used when the visitor has not
    | named a budget. Preferences always carry a price, so "no budget yet" has
    | to be spelled as a number large enough that it excludes nothing.
    |
    */

    'max_price' => (int) env('PROPERTIES_MAX_PRICE', 10_000_000_00),

];
