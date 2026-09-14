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

    /*
    |--------------------------------------------------------------------------
    | Search Result Limit
    |--------------------------------------------------------------------------
    |
    | How many homes to put on the map when many match. When fewer than
    | search_limit_full match, every match is returned so nothing is hidden.
    |
    */

    'search_limit' => (int) env('PROPERTIES_SEARCH_LIMIT', 500),

    'search_limit_full' => (int) env('PROPERTIES_SEARCH_LIMIT_FULL', 500),

    /*
    |--------------------------------------------------------------------------
    | AI Tool Marker Limit
    |--------------------------------------------------------------------------
    |
    | How many listings to embed in a search tool reply the model reads back.
    | The map reloads the full result set after each turn; this cap keeps long
    | conversations inside provider token limits.
    |
    */

    'ai_tool_marker_limit' => (int) env('PROPERTIES_AI_TOOL_MARKER_LIMIT', 30),

    /*
    |--------------------------------------------------------------------------
    | Default Map Bounds
    |--------------------------------------------------------------------------
    |
    | When a search returns no mappable homes, the map falls back to this Cork
    | county bounding box instead of zooming out to all of Ireland.
    |
    */

    'default_bbox' => [
        '-10.5', '51.35', '-7.4', '52.25',
    ],

];
