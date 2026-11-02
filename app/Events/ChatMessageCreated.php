<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Chat\Data\ChatMessageData;
use App\Models\GamePlayer;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class ChatMessageCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [1, 5, 10];

    public function __construct(public readonly ChatMessageData $message)
    {
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_values(GamePlayer::query()
            ->where('game_id', $this->message->gameId)
            ->whereNotNull('user_id')
            ->get(['id'])
            ->map(fn (GamePlayer $player): PrivateChannel => new PrivateChannel(
                "game-chat.{$this->message->gameId}.{$player->id}",
            ))
            ->all());
    }

    public function broadcastAs(): string
    {
        return 'chat.message.created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['message' => $this->message->toArray()];
    }
}
