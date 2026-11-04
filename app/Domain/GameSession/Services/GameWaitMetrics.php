<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Services;

use Illuminate\Log\LogManager;
use Throwable;

final class GameWaitMetrics
{
    public function __construct(private LogManager $logs)
    {
    }

    public function transport(int $gameId, string $transport, ?string $reason = null): void
    {
        $this->record('info', 'mcp.wait_transport', ['game_id' => $gameId, 'transport' => $transport, 'reason' => $reason]);
    }

    public function redisError(int $gameId, string $operation, ?Throwable $exception = null): void
    {
        $this->record('warning', 'mcp.redis_error', [
            'game_id' => $gameId,
            'operation' => $operation,
            'error_type' => $exception === null ? 'invalid_response' : $exception::class,
        ]);
    }

    /** @param array<string, int|string|null> $context */
    private function record(string $level, string $event, array $context): void
    {
        if (! config('game-mcp.metrics.enabled')) {
            return;
        }

        try {
            $this->logs->channel('mcp')->log($level, $event, $context);
        } catch (Throwable) {
            // Metrics must not interrupt waiting or retry a committed game action.
        }
    }
}
