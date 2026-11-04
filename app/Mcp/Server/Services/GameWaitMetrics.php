<?php

declare(strict_types=1);

namespace App\Mcp\Server\Services;

use App\Domain\GameSession\Services\GameWaitObserver;
use Illuminate\Log\LogManager;
use Throwable;

final class GameWaitMetrics implements GameWaitObserver
{
    public function __construct(private LogManager $logs)
    {
    }

    public function waiting(int $gameId, bool $notificationsAvailable): void
    {
        $this->transport($gameId, $notificationsAvailable ? 'redis' : 'poll', $notificationsAvailable
            ? null
            : (config('game-mcp.wait.enabled') ? 'redis_unavailable' : 'disabled'));
    }

    public function notificationsUnavailable(int $gameId): void
    {
        $this->transport($gameId, 'poll', 'redis_unavailable');
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

    public function asyncWait(int $gameId, string $reason, float $durationMilliseconds, int $checks): void
    {
        $this->record('info', 'mcp.async_wait', ['game_id' => $gameId, 'reason' => $reason, 'duration_ms' => round($durationMilliseconds, 3), 'checks' => $checks]);
    }

    /** @param array<string, int|float|string|null> $context */
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
