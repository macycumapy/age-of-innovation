<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyChooseFelineTownBonusAction;
use App\Domain\Game\Actions\ApplyChooseTownBooksAction;
use App\Domain\Game\Actions\ApplyInnovationRewardDistributionAction;
use App\Domain\Game\Actions\ApplyRewardBookDistributionAction;
use App\Domain\Game\Actions\ApplyScienceBonusBookDistributionAction;
use App\Domain\Game\Actions\ApplyStartingResourcesAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\RewardDistributionOptionData;
use App\Domain\Game\Enums\PendingInteractionType;
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
        $simulatedState = GameStateData::from($state->toArray());
        $player = collect($simulatedState->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $nextActiveUserId = match ($simulatedState->pendingInteraction?->type) {
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
            )->nextActiveUserId,
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
            )->nextActiveUserId,
            PendingInteractionType::ChooseScienceBonusBooks => $this->applyScienceBonusBookDistribution->execute(
                $simulatedState,
                $player,
                $option->bookCounts,
            )->nextActiveUserId ?? $player->userId,
            PendingInteractionType::ChooseStartingResources => $this->applyStartingResources->execute(
                $simulatedState,
                $player,
                $option->bookCounts,
                $option->knowledgeCounts,
            )->nextActiveUserId,
            default => throw new DomainException('Это распределение наград сейчас недоступно.'),
        };

        return new GameActionSimulationData($simulatedState, $nextActiveUserId);
    }
}
