<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\GameStatus;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGamePlayerReadinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');
        $gamePlayer = $this->route('gamePlayer');

        return $game instanceof Game
            && $gamePlayer instanceof GamePlayer
            && $game->status === GameStatus::Lobby
            && $gamePlayer->game_id === $game->id
            && $gamePlayer->user_id === $this->user()?->id;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'is_ready' => ['required', 'boolean'],
        ];
    }

    public function isReady(): bool
    {
        return $this->boolean('is_ready');
    }
}
