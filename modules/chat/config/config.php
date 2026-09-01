<?php

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

];
