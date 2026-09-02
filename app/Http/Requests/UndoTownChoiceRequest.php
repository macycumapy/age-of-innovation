<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\GamePhase;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;

final class UndoTownChoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->phase === GamePhase::Actions
            && $game->active_player_id === $this->user()?->id;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
