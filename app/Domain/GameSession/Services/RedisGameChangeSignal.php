<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Services;

use Illuminate\Contracts\Redis\Factory;
use Throwable;

final class RedisGameChangeSignal implements GameChangeSignal
{
    public const int MAX_ENTRIES = 128;

    public const int TTL_SECONDS = 86400;

    public function __construct(private Factory $redis)
    {
    }

    public function cursor(int $gameId): ?string
    {
        if (! config('game-mcp.wait.enabled')) {
            return null;
        }

        try {
            $entries = $this->redis->connection('game_signals')->command('xrevrange', [$this->key($gameId), '+', '-', 1]);

            if ($entries === []) {
                return '0-0';
            }

            $cursor = is_array($entries) ? array_key_first($entries) : null;

            return is_string($cursor) ? $cursor : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function wait(int $gameId, string $cursor, int $timeoutMilliseconds): ?string
    {
        if ($timeoutMilliseconds <= 0) {
            return $cursor;
        }
        if (! config('game-mcp.wait.enabled')) {
            return null;
        }

        try {
            $streams = $this->redis->connection('game_wait')->command('xread', [
                [$this->key($gameId) => $cursor], self::MAX_ENTRIES, $timeoutMilliseconds,
            ]);

            if ($streams === false || $streams === []) {
                return $cursor;
            }
            if (! is_array($streams)) {
                return null;
            }

            $entries = reset($streams);
            $nextCursor = is_array($entries) ? array_key_last($entries) : null;

            return is_string($nextCursor) ? $nextCursor : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function publish(int $gameId): void
    {
        if (! config('game-mcp.wait.enabled')) {
            return;
        }

        try {
            $this->redis->connection('game_signals')->command('eval', [<<<'LUA'
                redis.call('XADD', KEYS[1], 'MAXLEN', ARGV[1], '*', 'changed', '1')
                redis.call('EXPIRE', KEYS[1], ARGV[2])
                return 1
                LUA, [$this->key($gameId), self::MAX_ENTRIES, self::TTL_SECONDS], 1]);
        } catch (Throwable) {
            // Notifications are optional; publishing must not fail a committed game action.
        }
    }

    private function key(int $gameId): string
    {
        return "game:turn-signals:{$gameId}";
    }
}
