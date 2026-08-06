<?php

declare(strict_types=1);

namespace App\Models\Builders;

use App\Domain\Game\Enums\GameStatus;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** @extends Builder<Game> */
final class GameBuilder extends Builder
{
    public function availableTo(User $user): self
    {
        return $this->where(fn (self $query): self => $query
            ->where('status', GameStatus::Lobby)
            ->orWhereHas(
                'players',
                fn (Builder $players): Builder => $players->where('user_id', $user->id),
            ));
    }
}
