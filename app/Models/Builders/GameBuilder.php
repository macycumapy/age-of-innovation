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
    public function visibleSummariesFor(User $user): self
    {
        return $this
            ->select([
                'id',
                'status',
                'state->board->variant as map_variant',
                'created_at',
            ])
            ->availableTo($user)
            ->withExists([
                'players as is_joined' => fn (Builder $query): Builder => $query->where('user_id', $user->id),
            ])
            ->withCount('players')
            ->latest()
            ->latest('id');
    }

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
