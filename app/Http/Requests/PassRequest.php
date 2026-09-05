<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\RoundBonus;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PassRequest extends FormRequest
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

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $game = $this->route('game');
        $isFinalRound = $game instanceof Game && $game->state->round->number >= 6;

        return [
            'round_bonus' => [$isFinalRound ? 'nullable' : 'required', Rule::enum(RoundBonus::class)],
        ];
    }

    public function roundBonus(): ?RoundBonus
    {
        $roundBonus = $this->validated('round_bonus');

        return is_string($roundBonus) ? RoundBonus::from($roundBonus) : null;
    }
}
