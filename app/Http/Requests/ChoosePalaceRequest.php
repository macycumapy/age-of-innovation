<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChoosePalaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction?->type === PendingInteractionType::ChoosePalace;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $game = $this->route('game');
        $optionIds = $game instanceof Game
            ? $game->state->pendingInteraction?->optionIds ?? []
            : [];

        return [
            'palace_id' => ['required', Rule::enum(PalaceAbility::class), Rule::in($optionIds)],
        ];
    }

    public function palace(): PalaceAbility
    {
        return PalaceAbility::from((string) $this->validated('palace_id'));
    }
}
