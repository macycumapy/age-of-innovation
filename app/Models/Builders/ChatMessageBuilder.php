<?php

declare(strict_types=1);

namespace App\Models\Builders;

use App\Models\ChatMessage;
use App\Models\Game;
use Illuminate\Database\Eloquent\Builder;

/** @extends Builder<ChatMessage> */
final class ChatMessageBuilder extends Builder
{
    public const int PAGE_SIZE = 50;

    public function historyPageFor(Game $game, ?int $beforeId = null, ?int $afterId = null): self
    {
        return $this
            ->whereBelongsTo($game)
            ->with('user:id,name')
            ->when($beforeId !== null, fn (self $query): self => $query->where('id', '<', $beforeId))
            ->when($afterId !== null, fn (self $query): self => $query->where('id', '>', $afterId))
            ->orderBy('id', $afterId !== null ? 'asc' : 'desc')
            ->limit(self::PAGE_SIZE + 1);
    }
}
