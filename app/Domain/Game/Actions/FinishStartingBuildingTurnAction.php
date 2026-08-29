<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\PendingInteractionData;
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

final class FinishStartingBuildingTurnAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private DetermineStartingBuildingOrderAction $determineStartingBuildingOrder,
        private ResolveCompletedStartingSetupAction $resolveCompletedStartingSetup,
    ) {
    }

    public function execute(Game $game, User $user): Game
    {
        return DB::transaction(function () use ($game, $user): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $stateVersionBefore = $lockedGame->version;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::Setup || $lockedGame->active_player_id !== $user->id
                || $state->pendingStartingBuildingHexId === null
                || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['game' => 'Сначала установите стартовый дом.']);
            }

            $confirmedHexId = $state->pendingStartingBuildingHexId;
            $state->pendingStartingBuildingHexId = null;
            $state->startingBuildingTurnIndex++;
            $placementOrder = $this->determineStartingBuildingOrder->execute($lockedGame);

            if ($player->faction === Faction::Monks) {
                $playerState = collect($state->players)->firstWhere('playerId', $player->id);

                if ($playerState === null) {
                    throw ValidationException::withMessages(['game' => 'Не найдено состояние игрока.']);
                }

                $availableCompetencyIds = array_values(array_filter(
                    array_map(
                        static fn (Competency|string $competency): string => $competency instanceof Competency
                            ? $competency->value
                            : $competency,
                        $state->setupPool?->competencies ?? [],
                    ),
                    static fn (string $competencyId): bool => ! in_array(
                        $competencyId,
                        $playerState->competencyIds,
                        true,
                    ),
                ));
                $state->pendingInteraction = new PendingInteractionData(
                    PendingInteractionType::ChooseCompetency,
                    $player->id,
                    $availableCompetencyIds,
                );
                $nextPlayer = $player;
                $nextPhase = GamePhase::Setup;
            } elseif ($state->startingBuildingTurnIndex >= count($placementOrder)) {
                [$nextPlayer, $nextPhase] = $this->resolveCompletedStartingSetup->execute(
                    $state,
                    $lockedGame->players()->get(),
                );
            } else {
                $nextPlayer = $lockedGame->players()->whereKey($placementOrder[$state->startingBuildingTurnIndex])->first();
                $nextPhase = GamePhase::Setup;

                if (! $nextPlayer instanceof GamePlayer) {
                    throw ValidationException::withMessages(['game' => 'Нарушен порядок стартового выставления.']);
                }
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
                GameActionType::FinishStartingBuildingTurn,
                ['hex_id' => $confirmedHexId],
                [[
                    'type' => 'starting_building_turn_finished',
                    'hex_id' => $confirmedHexId,
                    'turn_index' => $state->startingBuildingTurnIndex,
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
