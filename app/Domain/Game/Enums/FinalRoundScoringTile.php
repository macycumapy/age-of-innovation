<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum FinalRoundScoringTile: string
{
    /** 2 ПО за построенную мастерскую. */
    case Workshop = 'workshop';
    /** 3 ПО за построенную гильдию. */
    case Guild = 'guild';
    /** 4 ПО за построенную школу. */
    case School = 'school';
    /** 3 ПО за мастерскую, построенную на краевом поле карты. */
    case EdgeWorkshop = 'edge_workshop';

    public function goal(): RoundScoringGoal
    {
        return match ($this) {
            self::Workshop, self::EdgeWorkshop => RoundScoringGoal::Workshop,
            self::Guild => RoundScoringGoal::Guild,
            self::School => RoundScoringGoal::School,
        };
    }

    public function victoryPoints(): int
    {
        return match ($this) {
            self::Workshop => 2,
            self::Guild, self::EdgeWorkshop => 3,
            self::School => 4,
        };
    }
}
