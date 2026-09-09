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

final class ResolvePowerOfferAction
{
    public function __construct(
        private ApplyPowerOfferDecisionAction $applyPowerOfferDecision,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, User $user, bool $accept): Game
    {
        return DB::transaction(function () use ($game, $user, $accept): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()
                ->whereKey($interaction?->playerId)
                ->whereBelongsTo($user)
                ->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::PowerOffer
                || ! $player instanceof GamePlayer) {
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
                'active_player_id' => $result['nextActiveUserId'],
                'state' => $state,
                'version' => $stateVersionAfter,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                $accept ? GameActionType::AcceptPower : GameActionType::DeclinePower,
                [
                    'offered_power' => $offeredPower,
                    'received_power' => $result['receivedPower'],
                    'victory_points_spent' => $result['victoryPointsSpent'],
                    'building_player_id' => $interaction->context['buildingPlayerId'] ?? null,
                    'built_hex_id' => $interaction->context['builtHexId'] ?? null,
                ],
                [[
                    'type' => $accept ? 'power_accepted' : 'power_declined',
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
