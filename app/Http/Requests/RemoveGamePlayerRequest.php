<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\GameStatus;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Foundation\Http\FormRequest;

class RemoveGamePlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');
        $gamePlayer = $this->route('gamePlayer');

        if (! $game instanceof Game
            || ! $gamePlayer instanceof GamePlayer
            || $game->status !== GameStatus::Lobby
            || $gamePlayer->game_id !== $game->id) {
            return false;
        }

        if ($gamePlayer->user_id === $this->user()?->id) {
            return true;
        }

        return $gamePlayer->seat !== 1
            && $game->players()->where('seat', 1)->where('user_id', $this->user()?->id)->exists();
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }
}
