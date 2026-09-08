<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\Innovation;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PerformInnovationAction
{
    public function __construct(
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, User $user, Innovation $innovation): Game
    {
        return DB::transaction(function () use ($game, $user, $innovation): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();
            $playerState = $player instanceof GamePlayer ? collect($state->players)->firstWhere('playerId', $player->id) : null;

            if ($lockedGame->phase !== GamePhase::Actions || $lockedGame->active_player_id !== $user->id
                || $state->pendingInteraction !== null || $state->round->hasTakenMainAction
                || ! $playerState instanceof GamePlayerStateData
                || ! in_array($innovation->value, $playerState->inventionIds, true)
                || ! $innovation->hasSpecialAction()
                || in_array($innovation->specialActionId(), $playerState->usedSpecialActionIds, true)) {
                throw ValidationException::withMessages(['innovation' => 'Особое действие этой инновации недоступно.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            if ($innovation === Innovation::Professor) {
                $playerState->resources->scholars = min($playerState->scholarPoolSize, $playerState->resources->scholars + 1);
                $playerState->victoryPoints += 3;
            } else {
                $playerState->unassignedSpades++;
                $eligibleHexIds = $this->findEligibleTerraformHexes->execute($state, $playerState, $playerState->homeland);

                if ($eligibleHexIds !== []) {
                    $state->pendingInteraction = new PendingInteractionData(
                        PendingInteractionType::SpendSpades,
                        $playerState->playerId,
                        $eligibleHexIds,
                        ['phase' => GamePhase::Actions->value, 'spadeCount' => 1, 'remainingSpades' => 1, 'targetTerrain' => $playerState->homeland->value],
                    );
                }
            }

            $playerState->usedSpecialActionIds[] = $innovation->specialActionId();
            $state->round->hasTakenMainAction = true;
            $lockedGame->update(['state' => $state, 'version' => $lockedGame->version + 1]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::SpecialAction,
                ['innovation' => $innovation->value],
                [['type' => 'innovation_action_used', 'player_id' => $player->id, 'innovation' => $innovation->value]],
                $stateVersionBefore,
                $lockedGame->version
            );

            return $lockedGame->refresh();
        });
    }
}
