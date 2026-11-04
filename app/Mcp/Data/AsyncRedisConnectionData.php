<?php

declare(strict_types=1);

namespace App\Mcp\Data;

use Clue\React\Redis\Client;

final class AsyncRedisConnectionData
{
    public ?Client $client = null;

    public bool $cancelled = false;
}
