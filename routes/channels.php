<?php

declare(strict_types=1);

use App\Broadcasting\GameChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('games.{gameId}', GameChannel::class);
