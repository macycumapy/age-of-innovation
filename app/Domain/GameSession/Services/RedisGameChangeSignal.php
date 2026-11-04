<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Services;

use Illuminate\Contracts\Redis\Factory;
use Throwable;

final class RedisGameChangeSignal implements GameChangeSignal
{
    public const int MAX_ENTRIES = 128;

    public const int TTL_SECONDS = 86400;

    public function __construct(private Factory $redis, private GameWaitMetrics $metrics)
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
            if (! is_string($cursor)) {
                $this->metrics->redisError($gameId, 'cursor');

                return null;
            }

            return $cursor;
        } catch (Throwable $exception) {
            $this->metrics->redisError($gameId, 'cursor', $exception);

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
                $this->metrics->redisError($gameId, 'wait');

                return null;
            }

            $entries = reset($streams);
            $nextCursor = is_array($entries) ? array_key_last($entries) : null;
            if (! is_string($nextCursor)) {
                $this->metrics->redisError($gameId, 'wait');

                return null;
            }

            return $nextCursor;
        } catch (Throwable $exception) {
            $this->metrics->redisError($gameId, 'wait', $exception);

            return null;
        }
    }

    public function publish(int $gameId): void
    {
        if (! config('game-mcp.wait.enabled')) {
            return;
        }

        try {
            $result = $this->redis->connection('game_signals')->command('eval', [<<<'LUA'
                redis.call('XADD', KEYS[1], 'MAXLEN', ARGV[1], '*', 'changed', '1')
                redis.call('EXPIRE', KEYS[1], ARGV[2])
                return 1
                LUA, [$this->key($gameId), self::MAX_ENTRIES, self::TTL_SECONDS], 1]);
            if ($result !== 1) {
                $this->metrics->redisError($gameId, 'publish');
            }
        } catch (Throwable $exception) {
            $this->metrics->redisError($gameId, 'publish', $exception);
        }
    }

    private function key(int $gameId): string
    {
        return "game:turn-signals:{$gameId}";
    }
}
