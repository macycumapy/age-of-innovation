<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\StartingResourcesResultData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Services\BookDistributionValidator;
use App\Domain\Game\Services\KnowledgeDistributionValidator;
use Illuminate\Validation\ValidationException;

final class ApplyStartingResourcesAction
{
    public function __construct(
        private ApplyBookDistributionAction $applyBookDistribution,
        private ApplyKnowledgeDistributionAction $applyKnowledgeDistribution,
        private BookDistributionValidator $bookDistributionValidator,
        private KnowledgeDistributionValidator $knowledgeDistributionValidator,
        private ResolveIncomePhaseAction $resolveIncomePhase,
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
    ): StartingResourcesResultData {
        $interaction = $state->pendingInteraction;
        $phase = $state->round->phase;
        $bookCount = (int) ($interaction?->context['bookCount'] ?? 0);
        $knowledgeStepCount = (int) ($interaction?->context['knowledgeStepCount'] ?? 0);

        if (! in_array($phase, [GamePhase::Setup, GamePhase::Income], true)
            || $interaction?->type !== PendingInteractionType::ChooseStartingResources
            || $interaction->playerId !== $player->playerId) {
            throw ValidationException::withMessages(['game' => 'Выбор ресурсов сейчас недоступен.']);
        }

        $this->bookDistributionValidator->validate($player, $bookCounts, $bookCount);
        $this->knowledgeDistributionValidator->validate($player, $knowledgeCounts, $knowledgeStepCount);
        $this->applyBookDistribution->execute($player, $bookCounts, $bookCount);
        $knowledgeResult = $this->applyKnowledgeDistribution->execute(
            $state,
            $player,
            $knowledgeCounts,
            $knowledgeStepCount,
        );
        $state->pendingInteraction = null;

        if ($phase === GamePhase::Income) {
            [$nextPlayer, $nextPhase, $incomeReceipts] = $this->resolveIncomePhase->execute($state);

            return new StartingResourcesResultData(
                $nextPlayer->userId,
                $nextPhase,
                $knowledgeResult->gainedPower,
                $knowledgeResult->victoryPoints,
                $incomeReceipts,
            );
        }

        return new StartingResourcesResultData(
            null,
            GamePhase::Setup,
            $knowledgeResult->gainedPower,
            $knowledgeResult->victoryPoints,
            [],
        );
    }
}
