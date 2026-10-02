<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Setup\Actions;

use App\Domain\GameEngine\Economy\Actions\ApplyBookDistributionAction;
use App\Domain\GameEngine\Economy\Services\BookDistributionValidator;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\Research\Actions\ApplyKnowledgeDistributionAction;
use App\Domain\GameEngine\Research\Services\KnowledgeDistributionValidator;
use App\Domain\GameEngine\Setup\Data\StartingResourcesResultData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Actions\ResolveIncomePhaseAction;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
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
                $nextPlayer->playerId,
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
