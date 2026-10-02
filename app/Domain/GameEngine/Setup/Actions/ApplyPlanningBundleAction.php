<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Setup\Actions;

use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\Setup\Data\PlanningBundleData;
use App\Domain\GameEngine\Setup\Data\PlanningBundleSelectionResultData;
use App\Domain\GameEngine\Setup\Data\PlayerPlanningSelectionData;
use App\Domain\GameEngine\Setup\Factories\GamePlayerStateFactory;
use App\Domain\GameEngine\Setup\Services\StartingBuildingOrderFinder;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Illuminate\Validation\ValidationException;

final class ApplyPlanningBundleAction
{
    public function __construct(
        private GamePlayerStateFactory $playerStateFactory,
        private InitializeNeutralKnowledgeFactionAction $initializeNeutralKnowledgeFaction,
        private StartingBuildingOrderFinder $startingBuildingOrderFinder,
    ) {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        ?int $userId,
        TerrainType $homeland,
    ): PlanningBundleSelectionResultData {
        if ($state->round->phase !== GamePhase::Setup
            || $state->setupPool === null
            || $state->pendingInteraction !== null
            || collect($state->players)->contains('playerId', $playerId)) {
            throw ValidationException::withMessages([
                'game' => 'Выбор стартового комплекта сейчас недоступен.',
            ]);
        }

        $bundle = collect($state->setupPool->planningBundles)->first(
            static fn (PlanningBundleData $bundle): bool => $bundle->homeland === $homeland,
        );
        if (! $bundle instanceof PlanningBundleData) {
            throw ValidationException::withMessages(['homeland' => 'Стартовый комплект не найден.']);
        }

        $isBundleSelected = collect($state->planningSelections)->contains(
            static fn (PlayerPlanningSelectionData $selection): bool => $selection->bundle->homeland === $homeland,
        );
        if ($isBundleSelected) {
            throw ValidationException::withMessages(['homeland' => 'Этот стартовый комплект уже недоступен.']);
        }

        $player = $this->playerStateFactory->createForPlayer($playerId, $userId, $bundle, $state);
        $gainedPower = $this->startingKnowledgePower($bundle, $player->resources->power);
        $state->planningSelections[] = new PlayerPlanningSelectionData($playerId, $bundle);
        $state->players[] = $player;
        $this->initializeNeutralKnowledgeFaction->execute($state);

        $requiresStartingChoice = $player->resources->books->unassigned > 0
            || $player->knowledge->unassignedSteps > 0;
        if ($requiresStartingChoice) {
            $state->pendingInteraction = new PendingInteractionData(
                type: PendingInteractionType::ChooseStartingResources,
                playerId: $playerId,
                optionIds: array_column(KnowledgeDiscipline::cases(), 'value'),
                context: [
                    'bookCount' => $player->resources->books->unassigned,
                    'knowledgeStepCount' => $player->knowledge->unassignedSteps,
                    'competencyIds' => [],
                ],
            );
        }

        return new PlanningBundleSelectionResultData(
            $player,
            $bundle,
            $gainedPower,
            $requiresStartingChoice ? $playerId : $this->nextPlayerId($state, $playerId),
        );
    }

    private function nextPlayerId(GameStateData $state, int $currentPlayerId): int
    {
        $currentIndex = array_search($currentPlayerId, $state->turnOrder, true);
        if ($currentIndex === false) {
            throw ValidationException::withMessages(['game' => 'Нарушен порядок игроков в состоянии партии.']);
        }

        $createdPlayerIds = array_column($state->players, 'playerId');
        foreach (range(1, count($state->turnOrder)) as $offset) {
            $candidateId = $state->turnOrder[($currentIndex + $offset) % count($state->turnOrder)];
            if (! in_array($candidateId, $createdPlayerIds, true)) {
                return $candidateId;
            }
        }

        $firstPlayerId = $this->startingBuildingOrderFinder->execute($state)[0] ?? null;
        if (! is_int($firstPlayerId)) {
            throw ValidationException::withMessages(['game' => 'Не найден первый игрок партии.']);
        }

        return $firstPlayerId;
    }

    private function startingKnowledgePower(PlanningBundleData $bundle, PowerBowlsStateData $power): int
    {
        $startingBowlTwo = match ($bundle->homeland) {
            TerrainType::Swamp => 9,
            TerrainType::Forest => 8,
            default => 7,
        };

        return max(0, $power->bowlTwo + (2 * $power->bowlThree) - $startingBowlTwo);
    }
}
