<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\Automation\Data\StrategicPlanData;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Services\BuildWorkshopOptionFinder;
use App\Domain\GameEngine\Board\Services\PaidTerraformingOptionFinder;
use App\Domain\GameEngine\Board\Services\UpgradeBuildingOptionFinder;
use App\Domain\GameEngine\Economy\Services\PlayerIncomeCalculator;
use App\Domain\GameEngine\Research\Services\DevelopmentAdvancementOptionFinder;
use App\Domain\GameEngine\Research\Services\MakeInnovationOptionFinder;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class CoinSpendingOpportunityEvaluator
{
    public function __construct(
        private BuildWorkshopOptionFinder $workshops,
        private UpgradeBuildingOptionFinder $upgrades,
        private DevelopmentAdvancementOptionFinder $development,
        private MakeInnovationOptionFinder $innovations,
        private PaidTerraformingOptionFinder $terraforming,
    ) {
    }

    public function execute(GameStateData $state, GamePlayerStateData $player, ?StrategicPlanData $plan = null): bool
    {
        if ($plan !== null && $plan->reservedCoins > $player->resources->coins) {
            return true;
        }
        $probe = $state->deepCopy();
        $probe->pendingInteraction = null;
        $probe->round->hasTakenMainAction = false;
        $probePlayer = collect($probe->players)->firstWhere('playerId', $player->playerId);
        if (! $probePlayer instanceof GamePlayerStateData) {
            return false;
        }
        $probePlayer->resources->coins = PHP_INT_MAX;
        if ($this->hasPurchase($probe, $probePlayer, $player->resources->coins)) {
            return true;
        }
        if ($state->round->number >= 6) {
            return false;
        }
        $income = PlayerIncomeCalculator::calculate($player, $state->board, includeRoundBonus: false);
        $probePlayer->resources->tools += $income->tools;
        $probePlayer->resources->scholars = min(
            $player->scholarPoolSize,
            $probePlayer->resources->scholars + $income->scholars
        );

        return $this->hasPurchase($probe, $probePlayer, $player->resources->coins + $income->coins);
    }

    private function hasPurchase(GameStateData $state, GamePlayerStateData $player, int $availableCoins): bool
    {
        if ($availableCoins < 2 && $this->workshops->execute($state, $player) !== []) {
            return true;
        }
        if ($availableCoins < 2) {
            $workshopCount = count(array_filter($state->board->hexes, static fn (BoardHexStateData $hex): bool =>
                $hex->building?->ownerPlayerId === $player->playerId && ! $hex->building->isNeutral
                && $hex->building->type === BuildingType::Workshop));
            if ($workshopCount < BuildingType::Workshop->supplyLimit()) {
                foreach ($this->terraforming->execute($state, $player) as $option) {
                    if ($option->toolCost + 1 <= $player->resources->tools) {
                        return true;
                    }
                }
            }
        }
        foreach ([...$this->upgrades->execute($state, $player), ...$this->development->execute($state, $player),
            ...$this->innovations->execute($state, $player)] as $option) {
            if ($option->coins > $availableCoins) {
                return true;
            }
        }

        return false;
    }
}
