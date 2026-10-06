<?php

declare(strict_types=1);

namespace App\Http\Requests\Game;

use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChoosePalaceRewardOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');
        return $game instanceof Game && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction?->type === PendingInteractionType::ChoosePalaceRewardOrder;
    }
    public function rules(): array
    {
        return ['first_reward' => ['required', Rule::in(['spades', 'bridges'])]];
    }
}
