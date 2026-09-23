<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\KnowledgeDistributionResultData;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Services\KnowledgeDistributionValidator;

final class ApplyKnowledgeDistributionAction
{
    public function __construct(
        private AdvanceKnowledgeAction $advanceKnowledge,
        private KnowledgeDistributionValidator $validator,
    ) {
    }

    /** @param array<string, int> $knowledgeCounts */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        array $knowledgeCounts,
        int $expectedCount,
    ): KnowledgeDistributionResultData {
        $this->validator->validate($player, $knowledgeCounts, $expectedCount);
        $gainedPower = 0;
        $victoryPoints = 0;

        foreach ($knowledgeCounts as $discipline => $count) {
            $knowledgeAdvance = $this->advanceKnowledge->execute(
                $state,
                $player,
                KnowledgeDiscipline::from($discipline),
                $count,
            );
            $gainedPower += $knowledgeAdvance->gainedPower;
            $victoryPoints += $knowledgeAdvance->victoryPoints;
        }

        $player->knowledge->unassignedSteps -= $expectedCount;
        $player->victoryPoints += $victoryPoints;

        return new KnowledgeDistributionResultData($victoryPoints, $gainedPower);
    }

}
