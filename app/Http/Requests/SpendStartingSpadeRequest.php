<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SpendStartingSpadeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction?->type === PendingInteractionType::SpendSpades;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $game = $this->route('game');
        $optionIds = $game instanceof Game
            ? $game->state->pendingInteraction?->optionIds ?? []
            : [];

        return [
            'hex_id' => ['required', 'string', Rule::in($optionIds)],
        ];
    }

    public function hexId(): string
    {
        return (string) $this->validated('hex_id');
    }
}
