<?php

use Modules\Chat\Ai\ChatAgent;

return [

    /*
    |--------------------------------------------------------------------------
    | Test Mode
    |--------------------------------------------------------------------------
    |
    | Answer with canned replies instead of calling the model, so the chat's
    | front end can be worked on without spending tokens -- and so the states
    | that are awkward to provoke on purpose (a tool coming up empty, a tool
    | erroring, the provider giving out) are a page refresh away.
    |
    | Refused outright in production regardless of this flag: serving invented
    | answers to real visitors is worse than the outage it would be hiding.
    |
    */

    'test_mode' => (bool) env('CHAT_TEST_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Chat AI Provider
    |--------------------------------------------------------------------------
    |
    | Chat streams on OpenAI. A leftover AI_PROVIDER=manus is ignored so an
    | old .env cannot select a provider that no longer exists.
    |
    */

    'ai_provider' => 'openai',

    'models' => [
        'openai' => env('CHAT_OPENAI_MODEL') ?: ChatAgent::MODEL,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | Authenticated chat endpoints that call the model or donated map services.
    | Format matches Laravel's throttle middleware: max attempts, decay minutes.
    |
    */

    'throttle' => [
        'stream' => env('CHAT_THROTTLE_STREAM', '30,1'),
        'map' => env('CHAT_THROTTLE_MAP', '60,1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Insights Window
    |--------------------------------------------------------------------------
    |
    | How many days of history the insights page reads. The token and tool
    | figures are aggregated in PHP because the SDK stores them as JSON text
    | rather than a native JSON column, which keeps the queries portable
    | across Postgres and the SQLite used by the test suite.
    |
    | ponytail: fine while a window holds a few thousand messages. Past that,
    | move to a summary table written on stream completion.
    |
    */

    'insights' => [
        'days' => (int) env('CHAT_INSIGHTS_DAYS', 30),
        'max_messages' => (int) env('CHAT_INSIGHTS_MAX_MESSAGES', 20000),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenStreetMap Cache
    |--------------------------------------------------------------------------
    |
    | How long a geocode or an Overpass answer is kept. Schools, parks and
    | supermarkets do not move, and a town's coordinates have not changed in
    | living memory, so a day was far too cautious for data of this kind.
    |
    | It is also a courtesy. Both APIs are donated infrastructure with no rate
    | limit worth the name, and Overpass throttles hard when leaned on -- the
    | slow, half-empty results we were seeing were mostly our own repeat
    | questions coming back to bite. Every cache hit is a request they do not
    | have to serve, and one a visitor does not have to wait for.
    |
    | Cached in the database store, so a deploy does not throw it away.
    |
    */

    'osm_cache_days' => (int) env('CHAT_OSM_CACHE_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Overpass Instances
    |--------------------------------------------------------------------------
    |
    | Tried in order until one answers. The main instance is the busiest thing
    | in OpenStreetMap and sheds load by returning 504 from its gateway within
    | a few seconds; measured from here, two requests in three failed that way.
    | The mirrors run the same software over the same planet, so any of them
    | gives the same answer, and a visitor should not have to care which one
    | happened to be up.
    |
    | Keep the main instance first. It is the best resourced when it is healthy,
    | so the mirrors should only ever carry the overflow -- they are donated
    | too, and a client that spreads its load evenly across all of them is just
    | being rude to three servers instead of one.
    |
    */

    'overpass_endpoints' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('CHAT_OVERPASS_ENDPOINTS', implode(',', [
            'https://overpass-api.de/api/interpreter',
            'https://overpass.kumi.systems/api/interpreter',
            'https://overpass.private.coffee/api/interpreter',
        ]))),
    ))),

];
