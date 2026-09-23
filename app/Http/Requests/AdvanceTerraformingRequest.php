<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;

final class AdvanceTerraformingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->phase->isActionPhase()
            && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction === null
            && ! $game->state->round->hasTakenMainAction;
    }

    public function rules(): array
    {
        return [];
    }
}
