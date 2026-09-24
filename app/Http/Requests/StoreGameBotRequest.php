<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\Game\Enums\GameStatus;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreGameBotRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->status === GameStatus::Lobby
            && $game->players()->where('seat', 1)->where('user_id', $this->user()?->id)->exists();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'difficulty' => ['required', Rule::enum(GameBotDifficulty::class)],
        ];
    }
}
