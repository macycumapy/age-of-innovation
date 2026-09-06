<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class GameHistoryChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public readonly int $gameId)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("games.{$this->gameId}");
    }

    public function broadcastAs(): string
    {
        return 'history.changed';
    }
}
