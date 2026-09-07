<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\RoundBonus;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChooseRoundBonusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction?->type === PendingInteractionType::ChooseRoundBonus;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['round_bonus' => ['required', Rule::enum(RoundBonus::class)]];
    }

    public function roundBonus(): RoundBonus
    {
        return RoundBonus::from((string) $this->validated('round_bonus'));
    }
}
