<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Services;

use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Research\Data\DevelopmentAdvancementOptionData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;

final class DevelopmentAdvancementOptionFinder
{
    /** @return list<DevelopmentAdvancementOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        if (! $state->round->phase->isActionPhase()
            || $state->pendingInteraction !== null
            || $state->round->hasTakenMainAction) {
            return [];
        }

        $options = [];

        if ($player->shippingLevel < 3
            && $player->resources->coins >= 4
            && $player->resources->scholars >= 1) {
            $options[] = new DevelopmentAdvancementOptionData(
                GameActionType::AdvanceShipping,
                $player->shippingLevel + 1,
                0,
                4,
                1,
            );
        }

        $terraformingCoinCost = $player->color === PlayerColor::Brown ? 1 : 5;
        if ($player->terraformingLevel < 2
            && $player->resources->tools >= 1
            && $player->resources->coins >= $terraformingCoinCost
            && $player->resources->scholars >= 1) {
            $options[] = new DevelopmentAdvancementOptionData(
                GameActionType::AdvanceTerraforming,
                $player->terraformingLevel + 1,
                1,
                $terraformingCoinCost,
                1,
            );
        }

        return $options;
    }
}
