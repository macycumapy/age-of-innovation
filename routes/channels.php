<?php

declare(strict_types=1);

use App\Broadcasting\GameChannel;
use App\Broadcasting\GameChatChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('games.{gameId}', GameChannel::class);
Broadcast::channel('game-chat.{gameId}.{gamePlayerId}', GameChatChannel::class);
