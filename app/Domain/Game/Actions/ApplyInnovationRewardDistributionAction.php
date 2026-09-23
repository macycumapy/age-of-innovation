<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\InnovationRewardDistributionResultData;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Services\BookDistributionValidator;
use App\Domain\Game\Services\KnowledgeDistributionValidator;
use Illuminate\Validation\ValidationException;

final class ApplyInnovationRewardDistributionAction
{
    public function __construct(
        private ApplyBookDistributionAction $applyBookDistribution,
        private ApplyKnowledgeDistributionAction $applyKnowledgeDistribution,
        private BookDistributionValidator $bookDistributionValidator,
        private KnowledgeDistributionValidator $knowledgeDistributionValidator,
    ) {
    }

    /**
     * @param array<string, int> $bookCounts
     * @param array<string, int> $knowledgeCounts
     */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        array $bookCounts,
        array $knowledgeCounts,
    ): InnovationRewardDistributionResultData {
        $interaction = $state->pendingInteraction;
        $bookCount = (int) ($interaction?->context['bookCount'] ?? 0);
        $knowledgeStepCount = (int) ($interaction?->context['knowledgeStepCount'] ?? 0);

        if ($interaction?->type !== PendingInteractionType::ChooseInnovationReward
            || $interaction->playerId !== $player->playerId) {
            throw ValidationException::withMessages(['game' => 'Нельзя распределить награду инновации.']);
        }

        $this->bookDistributionValidator->validate($player, $bookCounts, $bookCount);
        $this->knowledgeDistributionValidator->validate(
            $player,
            $knowledgeCounts,
            $knowledgeStepCount,
        );
        $this->applyBookDistribution->execute($player, $bookCounts, $bookCount);
        $knowledgeResult = $this->applyKnowledgeDistribution->execute(
            $state,
            $player,
            $knowledgeCounts,
            $knowledgeStepCount,
        );
        $state->pendingInteraction = null;

        return new InnovationRewardDistributionResultData(
            $player->userId,
            $knowledgeResult->victoryPoints,
            $knowledgeResult->gainedPower,
        );
    }
}
