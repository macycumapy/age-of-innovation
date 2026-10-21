<?php

declare(strict_types=1);

namespace App\Mcp\Stores;

use Illuminate\Support\Facades\Cache;
use Throwable;

final class GameContextSnapshotStore
{
    public const int TTL_SECONDS = 3600;

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function remember(array $context): array
    {
        unset($context['context_id']);
        try {
            $id = bin2hex(random_bytes(16));
            if (Cache::put($this->key($context['user_id'], $context['game_id'], $context['player_id'], $id), $context, self::TTL_SECONDS)) {
                $context['context_id'] = $id;
            }
        } catch (Throwable) {
            // Snapshot caching is optional; a cache outage must not fail a confirmed action.
        }

        return $context;
    }

    /** @return array<string, mixed>|null */
    public function find(int $userId, int $gameId, int $playerId, string $contextId): ?array
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $contextId) !== 1) {
            return null;
        }
        try {
            $context = Cache::get($this->key($userId, $gameId, $playerId, $contextId));
        } catch (Throwable) {
            return null;
        }

        if (! is_array($context) || ($context['format'] ?? null) !== 'compact-v2'
            || ($context['user_id'] ?? null) !== $userId || ($context['game_id'] ?? null) !== $gameId
            || ($context['player_id'] ?? null) !== $playerId || ! is_int($context['version'] ?? null)
            || ! is_array($context['state'] ?? null) || ! is_array($context['actions'] ?? null)
            || ! array_key_exists('staged_action', $context) || ! array_key_exists('active_player_id', $context)) {
            return null;
        }

        return $context;
    }

    private function key(int $userId, int $gameId, int $playerId, string $id): string
    {
        return "mcp:context:{$userId}:{$gameId}:{$playerId}:{$id}";
    }
}
