<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GamePhase;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpgradeBuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->phase === GamePhase::Actions
            && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction === null
            && ! $game->state->round->hasTakenMainAction;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hex_id' => ['required', 'string'],
            'target' => ['required', Rule::enum(BuildingType::class)],
        ];
    }
}
