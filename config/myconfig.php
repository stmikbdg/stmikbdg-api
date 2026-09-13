<?php

return [
    'database' => [
        'first_connection' => env('DB_CONNECTION', null),
        'second_connection' => env('SECOND_DB_CONNECTION', null),
    ],
    'api' => [
        'base_url' => is_null(env('APP_URL', null)) ? null : env('APP_URL').'api/',
    ],
    'login' => [
        'base_url' => env('LOGIN_BASE_URL', null),
    ],
    'rate_limit' => [
        'api_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 60),
    ],
];
