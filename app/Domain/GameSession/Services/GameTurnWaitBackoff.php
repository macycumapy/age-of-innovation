<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Services;

use Illuminate\Support\Facades\Cache;

final class GameTurnWaitBackoff
{
    public const int INITIAL_SECONDS = 20;

    public const int INCREMENT_SECONDS = 10;

    public const int MAXIMUM_SECONDS = 40;

    public function seconds(int $userId, int $gameId): int
    {
        $seconds = Cache::get($this->key($userId, $gameId));

        return is_int($seconds) && $seconds >= self::INITIAL_SECONDS
            ? min($seconds, self::MAXIMUM_SECONDS)
            : self::INITIAL_SECONDS;
    }

    public function increase(int $userId, int $gameId, int $seconds): void
    {
        Cache::put($this->key($userId, $gameId), min($seconds + self::INCREMENT_SECONDS, self::MAXIMUM_SECONDS), now()->addHour());
    }

    public function reset(int $userId, int $gameId): void
    {
        Cache::forget($this->key($userId, $gameId));
    }

    private function key(int $userId, int $gameId): string
    {
        return 'game:turn-wait:'.$gameId.':user:'.$userId;
    }
}
