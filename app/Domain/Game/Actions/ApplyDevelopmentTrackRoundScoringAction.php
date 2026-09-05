<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\RoundScoringGoal;
use App\Domain\Game\Enums\RoundScoringTile;

final class ApplyDevelopmentTrackRoundScoringAction
{
    public function execute(GameStateData $state, GamePlayerStateData $player, int $steps): int
    {
        $roundScoringTile = RoundScoringTile::tryFrom((string) $state->round->scoringTileId);
        $victoryPoints = $roundScoringTile?->goal() === RoundScoringGoal::ShippingOrTerraforming
            ? max(0, $steps) * 3
            : 0;
        $player->victoryPoints += $victoryPoints;

        return $victoryPoints;
    }
}
