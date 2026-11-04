<?php

declare(strict_types=1);

use App\Broadcasting\GameChannel;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('games.{gameId}', GameChannel::class);
Broadcast::channel('games', fn (User $user): bool => true);
