<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SpendStartingSpadeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');
        $user = $this->user();

        if (! $game instanceof Game || ! $user instanceof User) {
            return false;
        }

        $player = $game->players()->whereBelongsTo($user)->first();

        return $player instanceof GamePlayer
            && $game->isActivePlayer($player)
            && $game->state->pendingInteraction?->type === PendingInteractionType::SpendSpades;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $game = $this->route('game');
        $optionIds = $game instanceof Game
            ? $game->state->pendingInteraction->optionIds ?? []
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
