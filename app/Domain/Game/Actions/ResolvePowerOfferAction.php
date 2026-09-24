<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResolvePowerOfferAction
{
    public function __construct(
        private ApplyPowerOfferDecisionAction $applyPowerOfferDecision,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, GamePlayer $player, bool $accept): Game
    {
        return DB::transaction(function () use ($game, $player, $accept): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;

            if (! $lockedGame->phase->isActionPhase()
                || $player->game_id !== $lockedGame->id
                || ! $lockedGame->isActivePlayer($player)
                || $interaction?->type !== PendingInteractionType::PowerOffer
                || $interaction->playerId !== $player->id) {
                throw ValidationException::withMessages(['game' => 'Сейчас нельзя ответить на предложение Силы.']);
            }

            $offeredPower = (int) ($interaction->context['powerAmount'] ?? 0);
            $stateVersionBefore = $lockedGame->version;
            $result = $this->applyPowerOfferDecision->execute($state, $player->id, $accept);
            $stateVersionAfter = $lockedGame->version + 1;

            if ($result['advanceTurnCheckpoint']) {
                $state->round->isCurrentTurnIrrevocable = false;
                $state->turnStartSnapshot = null;
                $state->round->turnStartVersion = $stateVersionAfter;
            }

            $lockedGame->update([
                'active_game_player_id' => $result['nextActivePlayerId'],
                'state' => $state,
                'version' => $stateVersionAfter,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $player,
                $accept ? GameActionType::AcceptPower : GameActionType::DeclinePower,
                [
                    'offered_power' => $offeredPower,
                    'received_power' => $result['receivedPower'],
                    'victory_points_spent' => $result['victoryPointsSpent'],
                    'building_player_id' => $interaction->context['buildingPlayerId'] ?? null,
                    'built_hex_id' => $interaction->context['builtHexId'] ?? null,
                ],
                [[
                    'type' => $accept ? GameEventType::PowerAccepted->value : GameEventType::PowerDeclined->value,
                    'player_id' => $player->id,
                    'received_power' => $result['receivedPower'],
                    'victory_points_spent' => $result['victoryPointsSpent'],
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
