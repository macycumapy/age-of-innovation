<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfirmBridgeAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ApplyPlaceBridgeAction $applyPlaceBridge,
    ) {
    }

    public function execute(Game $game, GamePlayer $player): Game
    {
        return DB::transaction(function () use ($game, $player): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $fromHexId = $interaction?->context['selectedFromHexId'] ?? null;
            $toHexId = $interaction?->context['selectedToHexId'] ?? null;

            if (! $lockedGame->phase->isActionPhase()
                || ! $lockedGame->isActivePlayer($player)
                || $interaction?->type !== PendingInteractionType::PlaceBridge
                || ! is_string($fromHexId)
                || ! is_string($toHexId)
            ) {
                throw ValidationException::withMessages(['bridge' => 'Сначала выберите место для моста.']);
            }

            $stateVersionBefore = $lockedGame->version;
            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['bridge' => 'Не найдено состояние игрока.']);
            }

            $result = $this->applyPlaceBridge->execute(
                $state,
                $playerState,
                $fromHexId,
                $toHexId,
            );
            $source = $result->source;
            $lockedGame->active_game_player_id = $result->nextActivePlayerId;
            $lockedGame->state = $state;
            $lockedGame->version++;
            $lockedGame->save();
            $actionType = $source === 'power' ? GameActionType::PowerAction : GameActionType::SpecialAction;
            $payload = $source === 'power'
                ? [
                    'action' => 'build_bridge',
                    'sacrifice_amount' => (int) ($interaction->context['sacrificeAmount'] ?? 0),
                    'victory_points' => (int) ($interaction->context['victoryPoints'] ?? 0),
                    'from_hex_id' => $fromHexId,
                    'to_hex_id' => $toHexId,
                ]
                : ($source === 'faction' ? [
                    'faction' => 'moles',
                    'discipline' => null,
                    'from_hex_id' => $fromHexId,
                    'to_hex_id' => $toHexId,
                ] : [
                    'round_bonus' => 'bridge',
                    'discipline' => null,
                    'from_hex_id' => $fromHexId,
                    'to_hex_id' => $toHexId,
                ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $player,
                $actionType,
                $payload,
                [[
                    'type' => GameEventType::BridgeBuilt->value,
                    'player_id' => $player->id,
                    'from_hex_id' => $fromHexId,
                    'to_hex_id' => $toHexId,
                ]],
                $state->round->turnStartVersion ?? $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
