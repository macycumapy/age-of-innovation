<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChooseStartingCompetencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction?->type === PendingInteractionType::ChooseCompetency;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $game = $this->route('game');
        $optionIds = $game instanceof Game
            ? $game->state->pendingInteraction?->optionIds ?? []
            : [];

        return [
            'competency_id' => ['required', Rule::enum(Competency::class), Rule::in($optionIds)],
        ];
    }

    public function competency(): Competency
    {
        return Competency::from((string) $this->validated('competency_id'));
    }
}
