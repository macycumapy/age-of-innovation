<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BridgeStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfirmBridgeAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
    ) {
    }

    public function execute(Game $game, User $user): Game
    {
        return DB::transaction(function () use ($game, $user): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $fromHexId = $interaction?->context['selectedFromHexId'] ?? null;
            $toHexId = $interaction?->context['selectedToHexId'] ?? null;
            $player = $lockedGame->players()
                ->whereKey($interaction?->playerId)
                ->whereBelongsTo($user)
                ->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::PlaceBridge
                || ! is_string($fromHexId)
                || ! is_string($toHexId)
                || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['bridge' => 'Сначала выберите место для моста.']);
            }

            $stateVersionBefore = $lockedGame->version;
            $source = $interaction->context['source'] ?? null;

            if (! in_array($source, ['power', 'round_bonus', 'faction'], true)) {
                throw ValidationException::withMessages(['bridge' => 'Не определён источник строительства моста.']);
            }

            $state->board->bridges[] = new BridgeStateData($fromHexId, $toHexId, $player->id);
            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['bridge' => 'Не найдено состояние игрока.']);
            }

            $lockedGame->active_player_id = $this->createTownChoiceAfterBuilding->execute(
                $state,
                $playerState,
                $fromHexId,
                powerOffersResolved: true,
            );
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
                $user,
                $actionType,
                $payload,
                [[
                    'type' => 'bridge_built',
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
