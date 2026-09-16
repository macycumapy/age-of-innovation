<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\KnowledgeDiscipline;

final class AdvanceKnowledgeAction
{
    public function __construct(private GainPowerAction $gainPower)
    {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $playerState,
        KnowledgeDiscipline $discipline,
        int $steps,
    ): int {
        $currentLevel = $playerState->knowledge->{$discipline->value};
        $newLevel = min(12, $currentLevel + $steps);

        if ($currentLevel <= 7 && $newLevel >= 8
            && ! in_array($discipline, $playerState->knowledge->unlockedDisciplines, true)) {
            $activeTownKeyCount = count($playerState->townTileIds)
                - count($playerState->knowledge->unlockedDisciplines);

            if ($activeTownKeyCount <= 0) {
                $newLevel = 7;
            } else {
                $playerState->knowledge->unlockedDisciplines[] = $discipline;
            }
        }

        $topIsOccupied = collect($state->players)->contains(
            static fn (GamePlayerStateData $player): bool => $player->playerId !== $playerState->playerId
                && $player->knowledge->{$discipline->value} >= 12,
        );

        if ($topIsOccupied) {
            $newLevel = min(11, $newLevel);
        }

        $gainedPower = 0;

        foreach ([3 => 1, 5 => 2, 7 => 2, 12 => 3] as $level => $power) {
            if ($currentLevel < $level && $newLevel >= $level) {
                $gainedPower += $this->gainPower->execute($playerState, $power);
            }
        }

        $playerState->knowledge->{$discipline->value} = $newLevel;

        return $gainedPower;
    }
}
