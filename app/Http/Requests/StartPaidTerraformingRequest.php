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

        if (! $game instanceof Game || $game->active_player_id !== $this->user()?->id) {
            return false;
        }

        $interaction = $game->state->pendingInteraction;
        $continuesSpadeInteraction = in_array(
            $game->phase,
            [GamePhase::Setup, GamePhase::Actions, GamePhase::ScienceBonus],
            true,
        )
            && $interaction?->type === PendingInteractionType::SpendSpades
            && ! isset($interaction->context['selectedHexId']);

        return $continuesSpadeInteraction
            || ($game->phase === GamePhase::Actions && $interaction === null);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'hex_id' => ['required', 'string'],
            'use_available' => ['sometimes', 'boolean'],
            'use_tunnel' => ['sometimes', 'boolean'],
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

    public function useTunnel(): bool
    {
        return (bool) $this->validated('use_tunnel', false);
    }
}
