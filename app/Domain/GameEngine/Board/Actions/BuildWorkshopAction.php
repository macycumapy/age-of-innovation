<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Actions;

use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Enums\GameEventType;
use App\Domain\GameEngine\History\Actions\AppendGameHistoryAction;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BuildWorkshopAction
{
    public function __construct(
        private ApplyBuildWorkshopAction $applyBuildWorkshop,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, GamePlayer $player, string $hexId): Game
    {
        return DB::transaction(function () use ($game, $player, $hexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;

            if (! $lockedGame->phase->isActionPhase()
                || $player->game_id !== $lockedGame->id
                || ! $lockedGame->isActivePlayer($player)
                || $state->pendingInteraction !== null
                || $state->round->hasTakenMainAction) {
                throw ValidationException::withMessages(['building' => 'Сейчас нельзя построить дом.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['building' => 'Дом нельзя построить на выбранной клетке.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $result = $this->applyBuildWorkshop->execute($state, $playerState, $hexId);
            $lockedGame->update([
                'active_game_player_id' => $result->nextActivePlayerId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $player,
                GameActionType::BuildWorkshop,
                [
                    'hex_id' => $hexId,
                    'tools' => 1,
                    'coins' => 2,
                    'victory_points' => $result->victoryPoints,
                    'bonus_coins' => $result->bonusCoins,
                    'scoring_sources' => $result->scoringSources,
                ],
                [['type' => GameEventType::WorkshopBuilt->value, 'player_id' => $player->id, 'hex_id' => $hexId]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
