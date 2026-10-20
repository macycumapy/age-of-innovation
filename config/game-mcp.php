<?php

declare(strict_types=1);

return [
    'user_id' => env('GAME_MCP_USER_ID'),
    'metrics' => [
        'enabled' => env('GAME_MCP_METRICS_ENABLED', false),
    ],
];
