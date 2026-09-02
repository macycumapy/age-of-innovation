<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;

final class StartPaidTerraformingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->phase === GamePhase::Actions
            && $game->active_player_id === $this->user()?->id
            && ($game->state->pendingInteraction === null
                || ($game->state->pendingInteraction->type === PendingInteractionType::SpendSpades
                    && ! isset($game->state->pendingInteraction->context['selectedHexId'])));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'hex_id' => ['required', 'string'],
            'use_available' => ['sometimes', 'boolean'],
        ];
    }

    public function hexId(): string
    {
        return (string) $this->validated('hex_id');
    }

    public function useAvailable(): bool
    {
        return (bool) $this->validated('use_available', false);
    }
}
