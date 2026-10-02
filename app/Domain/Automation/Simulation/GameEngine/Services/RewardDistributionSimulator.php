<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\GameEngine\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\Interactions\Actions\ApplyRewardBookDistributionAction;
use App\Domain\GameEngine\Interactions\Data\RewardDistributionOptionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\Research\Actions\ApplyInnovationRewardDistributionAction;
use App\Domain\GameEngine\Setup\Actions\ApplyStartingResourcesAction;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Towns\Actions\ApplyChooseFelineTownBonusAction;
use App\Domain\GameEngine\Towns\Actions\ApplyChooseTownBooksAction;
use App\Domain\GameEngine\Turns\Actions\ApplyScienceBonusBookDistributionAction;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use DomainException;
use InvalidArgumentException;

final class RewardDistributionSimulator
{
    public function __construct(
        private ApplyChooseTownBooksAction $applyChooseTownBooks,
        private ApplyChooseFelineTownBonusAction $applyChooseFelineTownBonus,
        private ApplyRewardBookDistributionAction $applyRewardBookDistribution,
        private ApplyInnovationRewardDistributionAction $applyInnovationRewardDistribution,
        private ApplyScienceBonusBookDistributionAction $applyScienceBonusBookDistribution,
        private ApplyStartingResourcesAction $applyStartingResources,
    ) {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        RewardDistributionOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = $state->deepCopy();
        $player = collect($simulatedState->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $interactionType = $simulatedState->pendingInteraction?->type;
        $nextActivePlayerId = match ($interactionType) {
            PendingInteractionType::ChooseTownBooks => $this->applyChooseTownBooks->execute(
                $simulatedState,
                $player,
                $option->bookCounts,
            ),
            PendingInteractionType::ChooseFelineTownBonus => $this->applyChooseFelineTownBonus->execute(
                $simulatedState,
                $player,
                $option->bookCounts,
                $option->knowledgeCounts,
            )->nextActivePlayerId,
            PendingInteractionType::ChooseShippingBooks,
            PendingInteractionType::ChooseTerraformingBooks,
            PendingInteractionType::ChoosePalaceBooks => $this->applyRewardBookDistribution->execute(
                $simulatedState,
                $player,
                $option->bookCounts,
                $simulatedState->pendingInteraction->type,
            ),
            PendingInteractionType::ChooseInnovationReward => $this->applyInnovationRewardDistribution->execute(
                $simulatedState,
                $player,
                $option->bookCounts,
                $option->knowledgeCounts,
            )->nextActivePlayerId,
            PendingInteractionType::ChooseScienceBonusBooks => $this->applyScienceBonusBookDistribution->execute(
                $simulatedState,
                $player,
                $option->bookCounts,
            )->nextActivePlayerId ?? $player->playerId,
            PendingInteractionType::ChooseStartingResources => $this->simulateStartingResources(
                $simulatedState,
                $player,
                $option,
            ),
            default => throw new DomainException('Это распределение наград сейчас недоступно.'),
        };

        return new GameActionSimulationData($simulatedState, $nextActivePlayerId);
    }

    private function simulateStartingResources(
        GameStateData $state,
        GamePlayerStateData $player,
        RewardDistributionOptionData $option,
    ): ?int {
        $phase = $state->round->phase;
        $result = $this->applyStartingResources->execute(
            $state,
            $player,
            $option->bookCounts,
            $option->knowledgeCounts,
        );

        if ($phase !== GamePhase::Setup || $result->nextActivePlayerId !== null) {
            return $result->nextActivePlayerId;
        }

        $currentIndex = array_search($player->playerId, $state->turnOrder, true);
        if ($currentIndex === false) {
            return null;
        }

        $createdPlayerIds = array_column($state->players, 'playerId');
        foreach (range(1, count($state->turnOrder)) as $offset) {
            $candidateId = $state->turnOrder[($currentIndex + $offset) % count($state->turnOrder)];
            if (! in_array($candidateId, $createdPlayerIds, true)) {
                return $candidateId;
            }
        }

        return $state->turnOrder[0];
    }
}
