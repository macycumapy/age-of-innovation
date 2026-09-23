<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpgradeBuildingAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ApplyUpgradeBuildingAction $applyUpgradeBuilding,
    ) {
    }

    public function execute(Game $game, User $user, string $hexId, BuildingType $target): Game
    {
        return DB::transaction(function () use ($game, $user, $hexId, $target): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $state->pendingInteraction !== null
                || $state->round->hasTakenMainAction
                || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['building' => 'Это здание нельзя улучшить выбранным способом.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['building' => 'Не найдено состояние игрока.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $result = $this->applyUpgradeBuilding->execute($state, $playerState, $hexId, $target);
            $lockedGame->update([
                'active_player_id' => $result->nextActiveUserId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::UpgradeBuilding,
                [
                    'hex_id' => $hexId,
                    'source' => $result->source?->value,
                    'target' => $result->target->value,
                    'tools' => $result->tools,
                    'coins' => $result->coins,
                    'victory_points' => $result->victoryPoints,
                    'bonus_coins' => $result->bonusCoins,
                    'scoring_sources' => $result->scoringSources,
                ],
                [[
                    'type' => GameEventType::BuildingUpgraded->value,
                    'player_id' => $player->id,
                    'hex_id' => $hexId,
                    'source' => $result->source?->value,
                    'target' => $result->target->value,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
