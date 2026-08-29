<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChooseStartingCompetencyAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private DetermineStartingBuildingOrderAction $determineStartingBuildingOrder,
        private GrantCompetencyAction $grantCompetency,
        private ResolveCompletedStartingSetupAction $resolveCompletedStartingSetup,
    ) {
    }

    public function execute(Game $game, User $user, Competency $competency): Game
    {
        return DB::transaction(function () use ($game, $user, $competency): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $stateVersionBefore = $lockedGame->version;
            $player = $lockedGame->players()
                ->whereKey($interaction?->playerId)
                ->whereBelongsTo($user)
                ->first();

            if ($lockedGame->phase !== GamePhase::Setup
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::ChooseCompetency
                || ! $player instanceof GamePlayer
                || $player->faction !== Faction::Monks
                || ! in_array($competency->value, $interaction->optionIds, true)) {
                throw ValidationException::withMessages([
                    'competency_id' => 'Эта стартовая компетенция недоступна.',
                ]);
            }

            $playerStateIndex = null;

            foreach ($state->players as $index => $playerState) {
                if ($playerState->playerId === $player->id) {
                    $playerStateIndex = $index;
                    break;
                }
            }

            if ($playerStateIndex === null) {
                throw ValidationException::withMessages(['game' => 'Не найдено состояние игрока.']);
            }

            $playerState = $state->players[$playerStateIndex];
            $this->grantCompetency->execute(
                $playerState,
                $competency,
                $state->setupPool?->competencies ?? [],
            );
            $state->players[$playerStateIndex] = $playerState;
            $state->pendingInteraction = null;
            $placementOrder = $this->determineStartingBuildingOrder->execute($lockedGame);

            if ($state->startingBuildingTurnIndex >= count($placementOrder)) {
                [$nextPlayer, $nextPhase] = $this->resolveCompletedStartingSetup->execute(
                    $state,
                    $lockedGame->players()->get(),
                );
            } else {
                $nextPlayer = $lockedGame->players()->whereKey($placementOrder[$state->startingBuildingTurnIndex])->firstOrFail();
                $nextPhase = GamePhase::Setup;
            }

            $lockedGame->update([
                'phase' => $nextPhase,
                'active_player_id' => $nextPlayer->user_id,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::ChooseCompetency,
                ['competency_id' => $competency->value],
                [[
                    'type' => 'starting_competency_chosen',
                    'player_id' => $player->id,
                    'competency_id' => $competency->value,
                    'next_player_id' => $nextPlayer->id,
                    'next_phase' => $nextPhase->value,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
