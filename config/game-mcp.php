<?php

declare(strict_types=1);

return [
    'user_id' => env('GAME_MCP_USER_ID'),
    'wait' => [
        'enabled' => env('GAME_MCP_WAIT_ENABLED', true),
    ],
    'async' => [
        'backend' => env('GAME_MCP_BACKEND', 'tcp://app:9001'),
        'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('GAME_MCP_ALLOWED_ORIGINS', env('APP_URL', 'http://localhost'))))),
    ],
    'metrics' => [
        'enabled' => env('GAME_MCP_METRICS_ENABLED', false),
    ],
];
