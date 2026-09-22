<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResolvePalaceWaterTownAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ApplyPalaceWaterTownDecisionAction $applyPalaceWaterTownDecision,
    ) {
    }

    public function execute(Game $game, User $user, bool $accept, ?string $waterHexId): Game
    {
        return DB::transaction(function () use ($game, $user, $accept, $waterHexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()->whereKey($interaction?->playerId)->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::OfferPalaceWaterTown
                || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['town' => 'Сейчас нельзя подтвердить создание города через воду.']);
            }

            $stateVersionBefore = $lockedGame->version;
            $state->turnStartSnapshot = $state->toArray();
            $state->round->turnStartVersion = $stateVersionBefore;
            $result = $this->applyPalaceWaterTownDecision->execute($state, $player->id, $accept, $waterHexId);

            $lockedGame->update([
                'active_player_id' => $result->nextActiveUserId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                $accept ? GameActionType::AcceptPalaceWaterTown : GameActionType::DeclinePalaceWaterTown,
                [
                    'water_hex_id' => $accept ? $waterHexId : null,
                    'built_hex_id' => $result->builtHexId,
                    'town_hex_ids' => $result->townHexIds,
                    'queued_built_hex_ids' => $result->queuedBuiltHexIds,
                ],
                [['type' => $accept ? 'palace_water_town_accepted' : 'palace_water_town_declined', 'player_id' => $player->id]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
