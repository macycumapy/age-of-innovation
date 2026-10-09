<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\GameEngine\Scoring\Enums\RoundScoringGoal;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;

final class StrategicPriorities
{
    public static function settlementValue(GameStateData $state, int $value): int
    {
        if ($state->round->phase === GamePhase::Setup
            || RoundScoringTile::tryFrom((string) $state->round->scoringTileId)?->goal() === RoundScoringGoal::Town) {
            return $value;
        }
        $percent = match ($state->round->number) {
            1, 2 => 40,
            3 => 70,
            default => 100,
        };

        return intdiv($value * $percent, 100);
    }
}
