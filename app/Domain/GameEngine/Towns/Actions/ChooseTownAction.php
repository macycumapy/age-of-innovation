<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Towns\Actions;

use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Enums\GameEventType;
use App\Domain\GameEngine\History\Actions\AppendGameHistoryAction;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\Towns\Enums\TownTile;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChooseTownAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ApplyChooseTownAction $applyChooseTown,
    ) {
    }

    public function execute(Game $game, GamePlayer $player, TownTile $townTile): Game
    {
        return DB::transaction(function () use ($game, $player, $townTile): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;

            if (! $lockedGame->phase->isActionPhase()
                || $player->game_id !== $lockedGame->id
                || ! $lockedGame->isActivePlayer($player)
                || $interaction?->type !== PendingInteractionType::ChooseTown
                || $interaction->playerId !== $player->id) {
                throw ValidationException::withMessages(['town_tile' => 'Этот жетон города недоступен.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['town_tile' => 'Не найдено состояние игрока.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $result = $this->applyChooseTown->execute($state, $playerState, $townTile);

            $lockedGame->update([
                'active_game_player_id' => $result->nextActivePlayerId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $player,
                GameActionType::ChooseTown,
                [
                    'town_tile' => $townTile->value,
                    'town_id' => $result->townId,
                    'town_hex_ids' => $result->townHexIds,
                    'marker_hex_id' => $result->markerHexId,
                    'queued_built_hex_ids' => $interaction->context['queuedBuiltHexIds'] ?? [],
                    'victory_points' => $result->victoryPoints,
                    'gained_power' => $result->gainedPower,
                ],
                [['type' => GameEventType::TownFounded->value, 'player_id' => $player->id, 'town_tile' => $townTile->value]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
