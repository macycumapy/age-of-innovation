<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;

final class FinishActionTurnRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');
        $playerId = $game instanceof Game
            ? $game->players()->whereBelongsTo($this->user())->value('id')
            : null;

        return $game instanceof Game
            && $game->phase === GamePhase::Actions
            && $game->active_player_id === $this->user()?->id
            && ($game->state->pendingInteraction === null
                || ($game->state->pendingInteraction->type === PendingInteractionType::BuildWorkshopAfterTerraforming
                    && $game->state->pendingInteraction->playerId === $playerId))
            && $game->state->round->turnStartVersion !== null
            && $game->state->round->hasTakenMainAction;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
