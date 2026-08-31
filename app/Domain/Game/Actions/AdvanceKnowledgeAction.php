<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\KnowledgeDiscipline;

final class AdvanceKnowledgeAction
{
    public function __construct(private GainPowerAction $gainPower)
    {
    }

    public function execute(
        GamePlayerStateData $playerState,
        KnowledgeDiscipline $discipline,
        int $steps,
    ): void {
        $currentLevel = $playerState->knowledge->{$discipline->value};
        $newLevel = min(12, $currentLevel + $steps);

        foreach ([3 => 1, 5 => 2, 7 => 2, 12 => 3] as $level => $power) {
            if ($currentLevel < $level && $newLevel >= $level) {
                $this->gainPower->execute($playerState, $power);
            }
        }

        $playerState->knowledge->{$discipline->value} = $newLevel;
    }
}
