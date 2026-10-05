<?php

declare(strict_types=1);

namespace App\Http\Requests\Game;

use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class StartPaidTerraformingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');
        $user = $this->user();

        if (! $game instanceof Game || ! $user instanceof User) {
            return false;
        }

        $player = $game->players()->whereBelongsTo($user)->first();

        if (! $player instanceof GamePlayer || ! $game->isActivePlayer($player)) {
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
            || ($game->phase->isActionPhase() && $interaction === null);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'hex_id' => ['required', 'string'],
            'use_available' => ['sometimes', 'boolean'],
            'use_tunnel' => ['sometimes', 'boolean'],
            'use_flight' => ['sometimes', 'boolean'],
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

    public function useFlight(): bool
    {
        return (bool) $this->validated('use_flight', false);
    }
}
