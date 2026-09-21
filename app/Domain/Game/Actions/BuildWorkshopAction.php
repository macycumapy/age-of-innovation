<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BuildWorkshopAction
{
    public function __construct(
        private ApplyBuildWorkshopAction $applyBuildWorkshop,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, User $user, string $hexId): Game
    {
        return DB::transaction(function () use ($game, $user, $hexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $state->pendingInteraction !== null
                || $state->round->hasTakenMainAction
                || ! $player instanceof GamePlayer) {
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
                'active_player_id' => $result->nextActiveUserId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::BuildWorkshop,
                [
                    'hex_id' => $hexId,
                    'tools' => 1,
                    'coins' => 2,
                    'victory_points' => $result->victoryPoints,
                    'bonus_coins' => $result->bonusCoins,
                    'scoring_sources' => $result->scoringSources,
                ],
                [['type' => 'workshop_built', 'player_id' => $player->id, 'hex_id' => $hexId]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
