<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\ChooseFelineTownBonusResultData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Services\BookDistributionValidator;
use App\Domain\Game\Services\KnowledgeDistributionValidator;
use Illuminate\Validation\ValidationException;

final class ApplyChooseFelineTownBonusAction
{
    public function __construct(
        private ApplyBookDistributionAction $applyBookDistribution,
        private ApplyKnowledgeDistributionAction $applyKnowledgeDistribution,
        private BookDistributionValidator $bookDistributionValidator,
        private KnowledgeDistributionValidator $knowledgeDistributionValidator,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
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
        ?int $turnStartVersion = null,
    ): ChooseFelineTownBonusResultData {
        $interaction = $state->pendingInteraction;
        $continueBuildingHexId = $interaction?->context['continueBuildingAfterPowerHexId'] ?? null;
        $bookCount = (int) ($interaction?->context['bookCount'] ?? 0);
        $knowledgeStepCount = (int) ($interaction?->context['knowledgeStepCount'] ?? 0);

        if ($interaction?->type !== PendingInteractionType::ChooseFelineTownBonus
            || $interaction->playerId !== $player->playerId) {
            throw ValidationException::withMessages(['book_counts' => 'Нельзя распределить бонус Кошачьих.']);
        }

        $this->bookDistributionValidator->validate($player, $bookCounts, $bookCount);
        $this->knowledgeDistributionValidator->validate($player, $knowledgeCounts, $knowledgeStepCount);

        if ($state->turnStartSnapshot === null) {
            $state->turnStartSnapshot = $state->toArray();
            $state->round->turnStartVersion = $turnStartVersion;
        }

        $this->applyBookDistribution->execute($player, $bookCounts, $bookCount);
        $knowledgeResult = $this->applyKnowledgeDistribution->execute(
            $state,
            $player,
            $knowledgeCounts,
            $knowledgeStepCount,
        );
        $state->pendingInteraction = null;
        $nextActivePlayerId = $player->playerId;

        if (is_string($continueBuildingHexId)) {
            $nextActivePlayerId = $this->createTownChoiceAfterBuilding->execute(
                $state,
                $player,
                $continueBuildingHexId,
                powerOffersResolved: true,
            );
        }

        return new ChooseFelineTownBonusResultData(
            $nextActivePlayerId,
            $knowledgeResult->victoryPoints,
            $knowledgeResult->gainedPower,
            is_string($continueBuildingHexId) ? $continueBuildingHexId : null,
        );
    }
}
