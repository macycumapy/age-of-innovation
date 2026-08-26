<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\PlanningBundleData;
use App\Domain\Game\Data\PlayerPlanningSelectionData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Factories\GamePlayerStateFactory;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChoosePlanningBundleAction
{
    public function __construct(
        private GamePlayerStateFactory $playerStateFactory,
        private DetermineNextPlanningPlayerAction $determineNextPlanningPlayer,
    ) {
    }

    public function execute(Game $game, User $user, TerrainType $homeland): Game
    {
        return DB::transaction(function () use ($game, $user, $homeland): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);

            if ($lockedGame->status !== GameStatus::Active
                || $lockedGame->phase !== GamePhase::Setup
                || $lockedGame->state->setupPool === null) {
                throw ValidationException::withMessages([
                    'game' => 'Выбор стартового комплекта сейчас недоступен.',
                ]);
            }

            if ($lockedGame->active_player_id !== $user->id) {
                throw ValidationException::withMessages([
                    'game' => 'Сейчас стартовый комплект выбирает другой игрок.',
                ]);
            }

            $player = $lockedGame->players()->whereBelongsTo($user)->firstOrFail();

            if ($player->faction !== null) {
                throw ValidationException::withMessages([
                    'game' => 'Стартовый комплект уже выбран.',
                ]);
            }

            $setupPool = $lockedGame->state->setupPool;
            $bundle = collect($setupPool->planningBundles)
                ->first(
                    static fn (PlanningBundleData $bundle): bool => $bundle->homeland === $homeland,
                );

            if ($bundle === null) {
                throw ValidationException::withMessages([
                    'homeland' => 'Стартовый комплект не найден.',
                ]);
            }

            $state = $lockedGame->state;
            $isBundleSelected = collect($state->planningSelections)->contains(
                static fn (PlayerPlanningSelectionData $selection): bool => $selection->bundle->homeland === $bundle->homeland,
            );

            if ($isBundleSelected) {
                throw ValidationException::withMessages([
                    'homeland' => 'Этот стартовый комплект уже недоступен.',
                ]);
            }

            $playerState = $this->playerStateFactory->create($player, $bundle);

            $player->update([
                'color' => $playerState->color,
                'faction' => $bundle->faction,
                'homeland' => $bundle->homeland,
            ]);

            $state->planningSelections = [
                ...$state->planningSelections,
                new PlayerPlanningSelectionData($player->id, $bundle),
            ];
            $state->players = [...$state->players, $playerState];
            $requiresStartingChoice = $playerState->resources->books->unassigned > 0
                || $playerState->knowledge->unassignedSteps > 0;

            if ($requiresStartingChoice) {
                $state->pendingInteraction = new PendingInteractionData(
                    type: PendingInteractionType::ChooseStartingResources,
                    playerId: $player->id,
                    optionIds: array_column(KnowledgeDiscipline::cases(), 'value'),
                    context: [
                        'bookCount' => $playerState->resources->books->unassigned,
                        'knowledgeStepCount' => $playerState->knowledge->unassignedSteps,
                    ],
                );
            }

            $nextPlayerId = $requiresStartingChoice
                ? $player->user_id
                : $this->determineNextPlanningPlayer->execute($lockedGame, $player)->user_id;

            $lockedGame->update([
                'active_player_id' => $nextPlayerId,
                'version' => $lockedGame->version + 1,
                'state' => $state,
            ]);

            return $lockedGame->refresh();
        });
    }
}
