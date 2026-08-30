<?php

return [

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
