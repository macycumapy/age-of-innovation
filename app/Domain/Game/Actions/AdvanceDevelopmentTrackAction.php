<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\PlayerColor;

class AdvanceDevelopmentTrackAction
{
    /** @return array{steps: int, books: int, victoryPoints: int} */
    public function advanceShipping(GamePlayerStateData $player, int $steps = 1): array
    {
        $currentLevel = $player->shippingLevel;
        $newLevel = min(3, $currentLevel + max(0, $steps));
        $levelRewards = $player->color === PlayerColor::Blue
            ? [
                1 => ['books' => 0, 'victoryPoints' => 0],
                2 => ['books' => 0, 'victoryPoints' => 3],
                3 => ['books' => 2, 'victoryPoints' => 0],
            ]
            : [
                1 => ['books' => 0, 'victoryPoints' => 2],
                2 => ['books' => 2, 'victoryPoints' => 0],
                3 => ['books' => 0, 'victoryPoints' => 4],
            ];
        $reward = $this->rewardForReachedLevels($currentLevel, $newLevel, $levelRewards);
        $player->shippingLevel = $newLevel;
        $player->resources->books->unassigned += $reward['books'];
        $player->victoryPoints += $reward['victoryPoints'];

        return ['steps' => $newLevel - $currentLevel, ...$reward];
    }

    /** @return array{steps: int, books: int, victoryPoints: int} */
    public function advanceTerraforming(GamePlayerStateData $player, int $steps = 1): array
    {
        $currentLevel = $player->terraformingLevel;
        $newLevel = min(2, $currentLevel + max(0, $steps));
        $reward = $this->rewardForReachedLevels($currentLevel, $newLevel, [
            1 => ['books' => 2, 'victoryPoints' => 0],
            2 => ['books' => 0, 'victoryPoints' => 6],
        ]);
        $player->terraformingLevel = $newLevel;
        $player->resources->books->unassigned += $reward['books'];
        $player->victoryPoints += $reward['victoryPoints'];

        return ['steps' => $newLevel - $currentLevel, ...$reward];
    }

    /**
     * @param array<int, array{books: int, victoryPoints: int}> $levelRewards
     * @return array{books: int, victoryPoints: int}
     */
    private function rewardForReachedLevels(int $currentLevel, int $newLevel, array $levelRewards): array
    {
        $reward = ['books' => 0, 'victoryPoints' => 0];

        foreach ($levelRewards as $level => $levelReward) {
            if ($currentLevel < $level && $newLevel >= $level) {
                $reward['books'] += $levelReward['books'];
                $reward['victoryPoints'] += $levelReward['victoryPoints'];
            }
        }

        return $reward;
    }
}
