<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;

final class PlaceNeutralInnovationBuildingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction?->type === PendingInteractionType::PlaceNeutralBuilding;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['hex_id' => ['required', 'string']];
    }
}
