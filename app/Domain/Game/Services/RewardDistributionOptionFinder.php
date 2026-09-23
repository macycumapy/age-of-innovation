<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\RewardDistributionOptionData;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;

final class RewardDistributionOptionFinder
{
    /** @return list<RewardDistributionOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        $interaction = $state->pendingInteraction;
        if (! in_array($interaction?->type, [
            PendingInteractionType::ChooseTownBooks,
            PendingInteractionType::ChooseFelineTownBonus,
            PendingInteractionType::ChooseScienceBonusBooks,
            PendingInteractionType::ChooseShippingBooks,
            PendingInteractionType::ChooseTerraformingBooks,
            PendingInteractionType::ChoosePalaceBooks,
            PendingInteractionType::ChooseInnovationReward,
        ], true)
            || $interaction->playerId !== $player->playerId) {
            return [];
        }

        $bookCount = (int) ($interaction->context['bookCount'] ?? 0);
        $knowledgeStepCount = (int) ($interaction->context['knowledgeStepCount'] ?? 0);
        if ($bookCount < 0
            || $knowledgeStepCount < 0
            || $player->resources->books->unassigned < $bookCount
            || (in_array($interaction->type, [
                PendingInteractionType::ChooseFelineTownBonus,
                PendingInteractionType::ChooseInnovationReward,
            ], true)
                && $player->knowledge->unassignedSteps < $knowledgeStepCount)) {
            return [];
        }
        $disciplineIds = array_column(KnowledgeDiscipline::cases(), 'value');
        $bookDistributions = $this->distributions($disciplineIds, $bookCount);
        $knowledgeDistributions = $this->distributions($disciplineIds, $knowledgeStepCount);
        $options = [];

        foreach ($bookDistributions as $bookCounts) {
            foreach ($knowledgeDistributions as $knowledgeCounts) {
                $options[] = new RewardDistributionOptionData($bookCounts, $knowledgeCounts);
            }
        }

        return $options;
    }

    /**
     * @param list<string> $disciplineIds
     * @return list<array<string, int>>
     */
    private function distributions(array $disciplineIds, int $count): array
    {
        $discipline = array_shift($disciplineIds);
        if (! is_string($discipline)) {
            return $count === 0 ? [[]] : [];
        }

        $distributions = [];
        for ($assigned = 0; $assigned <= $count; $assigned++) {
            foreach ($this->distributions($disciplineIds, $count - $assigned) as $remaining) {
                $distributions[] = [$discipline => $assigned, ...$remaining];
            }
        }

        return $distributions;
    }
}
