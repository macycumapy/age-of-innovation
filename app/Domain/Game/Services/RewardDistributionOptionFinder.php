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
        if ($interaction?->type !== PendingInteractionType::ChooseTownBooks
            || $interaction->playerId !== $player->playerId) {
            return [];
        }

        $bookCount = (int) ($interaction->context['bookCount'] ?? 0);

        return array_map(
            static fn (array $bookCounts): RewardDistributionOptionData => new RewardDistributionOptionData($bookCounts),
            $this->distributions(array_column(KnowledgeDiscipline::cases(), 'value'), $bookCount),
        );
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
