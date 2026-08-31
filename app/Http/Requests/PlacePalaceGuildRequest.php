<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PlacePalaceGuildRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction?->type === PendingInteractionType::PlacePalaceGuild;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $game = $this->route('game');

        return [
            'hex_id' => [
                'required',
                'string',
                Rule::in($game instanceof Game ? $game->state->pendingInteraction?->optionIds ?? [] : []),
            ],
        ];
    }
}
