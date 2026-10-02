<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Scoring\Actions;

use App\Domain\GameEngine\Scoring\Enums\RoundScoringGoal;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

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
