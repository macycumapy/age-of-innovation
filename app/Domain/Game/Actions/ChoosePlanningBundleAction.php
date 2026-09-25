<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\TerrainType;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChoosePlanningBundleAction
{
    public function __construct(
        private ApplyPlanningBundleAction $applyPlanningBundle,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, GamePlayer $player, TerrainType $homeland): Game
    {
        return DB::transaction(function () use ($game, $player, $homeland): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $stateVersionBefore = $lockedGame->version;

            if ($lockedGame->status !== GameStatus::Active
                || $lockedGame->phase !== GamePhase::Setup
                || $player->game_id !== $lockedGame->id
                || ! $lockedGame->isActivePlayer($player)
                || $player->faction !== null) {
                throw ValidationException::withMessages([
                    'game' => 'Выбор стартового комплекта сейчас недоступен.',
                ]);
            }

            $state = $lockedGame->state;
            $result = $this->applyPlanningBundle->execute(
                $state,
                $player->id,
                $player->user_id,
                $homeland,
            );
            $player->update([
                'color' => $result->player->color,
                'faction' => $result->bundle->faction,
                'homeland' => $result->bundle->homeland,
            ]);
            $lockedGame->update([
                'active_game_player_id' => $result->nextActivePlayerId,
                'version' => $lockedGame->version + 1,
                'state' => $state,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $player,
                GameActionType::ChoosePlanningBundle,
                [
                    'homeland' => $homeland->value,
                    'gained_power' => $result->gainedPower,
                ],
                [[
                    'type' => GameEventType::PlanningBundleChosen->value,
                    'player_id' => $player->id,
                    'homeland' => $result->bundle->homeland->value,
                    'faction' => $result->bundle->faction->value,
                    'round_bonus' => $result->bundle->roundBonus->value,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
