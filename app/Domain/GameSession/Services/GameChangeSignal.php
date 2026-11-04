<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Services;

interface GameChangeSignal
{
    /** Returns null when notifications are unavailable. */
    public function cursor(int $gameId): ?string;

    /** Returns the next cursor, the same cursor on timeout, or null on failure. */
    public function wait(int $gameId, string $cursor, int $timeoutMilliseconds): ?string;

    public function publish(int $gameId): void;
}
