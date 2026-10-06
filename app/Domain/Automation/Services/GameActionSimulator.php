<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\Automation\Simulation\Board\Services\BuildWorkshopSimulator;
use App\Domain\Automation\Simulation\Board\Services\PaidTerraformingSimulator;
use App\Domain\Automation\Simulation\Board\Services\PlaceAnnexSimulator;
use App\Domain\Automation\Simulation\Board\Services\PlaceBridgeSimulator;
use App\Domain\Automation\Simulation\Board\Services\PlaceNeutralBuildingSimulator;
use App\Domain\Automation\Simulation\Board\Services\PlacePalaceGuildSimulator;
use App\Domain\Automation\Simulation\Board\Services\SkipBridgeSimulator;
use App\Domain\Automation\Simulation\Board\Services\SpendSpadesSimulator;
use App\Domain\Automation\Simulation\Board\Services\UpgradeBuildingSimulator;
use App\Domain\Automation\Simulation\Board\Services\WorkshopAfterTerraformingSimulator;
use App\Domain\Automation\Simulation\Economy\Services\BookActionSimulator;
use App\Domain\Automation\Simulation\Economy\Services\PowerActionSimulator;
use App\Domain\Automation\Simulation\Economy\Services\PowerOfferSimulator;
use App\Domain\Automation\Simulation\Economy\Services\ResourceConversionSimulator;
use App\Domain\Automation\Simulation\GameEngine\Services\ChooseRoundBonusSimulator;
use App\Domain\Automation\Simulation\GameEngine\Services\PassSimulator;
use App\Domain\Automation\Simulation\GameEngine\Services\PlanningBundleSimulator;
use App\Domain\Automation\Simulation\GameEngine\Services\RewardDistributionSimulator;
use App\Domain\Automation\Simulation\GameEngine\Services\StartingBuildingSimulator;
use App\Domain\Automation\Simulation\PlayerAbilities\Services\ChoosePalaceSimulator;
use App\Domain\Automation\Simulation\PlayerAbilities\Services\PalaceActionSimulator;
use App\Domain\Automation\Simulation\PlayerAbilities\Services\PlayerSpecialActionSimulator;
use App\Domain\Automation\Simulation\Research\Services\ChooseCompetencySimulator;
use App\Domain\Automation\Simulation\Research\Services\DevelopmentAdvancementSimulator;
use App\Domain\Automation\Simulation\Research\Services\InnovationSpecialActionSimulator;
use App\Domain\Automation\Simulation\Research\Services\MakeInnovationSimulator;
use App\Domain\Automation\Simulation\Research\Services\SendScholarSimulator;
use App\Domain\Automation\Simulation\Towns\Services\ChooseTownSimulator;
use App\Domain\Automation\Simulation\Towns\Services\PalaceWaterTownSimulator;
use App\Domain\GameEngine\Board\Data\BuildWorkshopOptionData;
use App\Domain\GameEngine\Board\Data\PaidTerraformingOptionData;
use App\Domain\GameEngine\Board\Data\PlaceAnnexOptionData;
use App\Domain\GameEngine\Board\Data\PlaceBridgeOptionData;
use App\Domain\GameEngine\Board\Data\PlaceNeutralBuildingOptionData;
use App\Domain\GameEngine\Board\Data\PlacePalaceGuildOptionData;
use App\Domain\GameEngine\Board\Data\SkipBridgeOptionData;
use App\Domain\GameEngine\Board\Data\SpendSpadesOptionData;
use App\Domain\GameEngine\Board\Data\UpgradeBuildingOptionData;
use App\Domain\GameEngine\Board\Data\WorkshopAfterTerraformingOptionData;
use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Economy\Data\BookActionOptionData;
use App\Domain\GameEngine\Economy\Data\PowerActionOptionData;
use App\Domain\GameEngine\Economy\Data\PowerOfferOptionData;
use App\Domain\GameEngine\Economy\Data\ResourceExchangeOptionData;
use App\Domain\GameEngine\Economy\Data\SacrificePowerOptionData;
use App\Domain\GameEngine\Interactions\Data\RewardDistributionOptionData;
use App\Domain\GameEngine\PlayerAbilities\Data\ChoosePalaceOptionData;
use App\Domain\GameEngine\PlayerAbilities\Data\ChoosePalaceRewardOrderOptionData;
use App\Domain\GameEngine\PlayerAbilities\Data\PalaceActionOptionData;
use App\Domain\GameEngine\PlayerAbilities\Data\PlayerSpecialActionOptionData;
use App\Domain\GameEngine\Research\Data\ChooseCompetencyOptionData;
use App\Domain\GameEngine\Research\Data\DevelopmentAdvancementOptionData;
use App\Domain\GameEngine\Research\Data\InnovationSpecialActionOptionData;
use App\Domain\GameEngine\Research\Data\MakeInnovationOptionData;
use App\Domain\GameEngine\Research\Data\SendScholarOptionData;
use App\Domain\GameEngine\Setup\Data\PlanningBundleOptionData;
use App\Domain\GameEngine\Setup\Data\StartingBuildingOptionData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Towns\Data\ChooseTownOptionData;
use App\Domain\GameEngine\Towns\Data\PalaceWaterTownOptionData;
use App\Domain\GameEngine\Turns\Data\ChooseRoundBonusOptionData;
use App\Domain\GameEngine\Turns\Data\PassOptionData;
use DomainException;

final class GameActionSimulator
{
    public function __construct(
        private PlanningBundleSimulator $planningBundleSimulator,
        private StartingBuildingSimulator $startingBuildingSimulator,
        private BookActionSimulator $bookActionSimulator,
        private PowerActionSimulator $powerActionSimulator,
        private BuildWorkshopSimulator $buildWorkshopSimulator,
        private UpgradeBuildingSimulator $upgradeBuildingSimulator,
        private PaidTerraformingSimulator $paidTerraformingSimulator,
        private DevelopmentAdvancementSimulator $developmentAdvancementSimulator,
        private SendScholarSimulator $sendScholarSimulator,
        private MakeInnovationSimulator $makeInnovationSimulator,
        private PassSimulator $passSimulator,
        private ChooseRoundBonusSimulator $chooseRoundBonusSimulator,
        private InnovationSpecialActionSimulator $innovationSpecialActionSimulator,
        private PalaceActionSimulator $palaceActionSimulator,
        private PlayerSpecialActionSimulator $playerSpecialActionSimulator,
        private ResourceConversionSimulator $resourceConversionSimulator,
        private PlaceAnnexSimulator $placeAnnexSimulator,
        private PowerOfferSimulator $powerOfferSimulator,
        private ChooseTownSimulator $chooseTownSimulator,
        private WorkshopAfterTerraformingSimulator $workshopAfterTerraformingSimulator,
        private PalaceWaterTownSimulator $palaceWaterTownSimulator,
        private ChoosePalaceSimulator $choosePalaceSimulator,
        private ChooseCompetencySimulator $chooseCompetencySimulator,
        private PlaceNeutralBuildingSimulator $placeNeutralBuildingSimulator,
        private PlaceBridgeSimulator $placeBridgeSimulator,
        private SkipBridgeSimulator $skipBridgeSimulator,
        private SpendSpadesSimulator $spendSpadesSimulator,
        private PlacePalaceGuildSimulator $placePalaceGuildSimulator,
        private RewardDistributionSimulator $rewardDistributionSimulator,
    ) {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        GameActionOption $option,
    ): GameActionSimulationData {
        return match (true) {
            $option instanceof PlanningBundleOptionData => $this->planningBundleSimulator->execute($state, $playerId, $option),
            $option instanceof StartingBuildingOptionData => $this->startingBuildingSimulator->execute($state, $playerId, $option),
            $option instanceof BookActionOptionData => $this->bookActionSimulator->execute($state, $playerId, $option),
            $option instanceof PowerActionOptionData => $this->powerActionSimulator->execute($state, $playerId, $option),
            $option instanceof BuildWorkshopOptionData => $this->buildWorkshopSimulator->execute($state, $playerId, $option),
            $option instanceof UpgradeBuildingOptionData => $this->upgradeBuildingSimulator->execute($state, $playerId, $option),
            $option instanceof PaidTerraformingOptionData => $this->paidTerraformingSimulator->execute($state, $playerId, $option),
            $option instanceof DevelopmentAdvancementOptionData => $this->developmentAdvancementSimulator->execute($state, $playerId, $option),
            $option instanceof SendScholarOptionData => $this->sendScholarSimulator->execute($state, $playerId, $option),
            $option instanceof MakeInnovationOptionData => $this->makeInnovationSimulator->execute($state, $playerId, $option),
            $option instanceof PassOptionData => $this->passSimulator->execute($state, $playerId, $option),
            $option instanceof ChooseRoundBonusOptionData => $this->chooseRoundBonusSimulator->execute($state, $playerId, $option),
            $option instanceof InnovationSpecialActionOptionData => $this->innovationSpecialActionSimulator->execute($state, $playerId, $option),
            $option instanceof PalaceActionOptionData => $this->palaceActionSimulator->execute($state, $playerId, $option),
            $option instanceof PlayerSpecialActionOptionData => $this->playerSpecialActionSimulator->execute($state, $playerId, $option),
            $option instanceof ResourceExchangeOptionData,
            $option instanceof SacrificePowerOptionData => $this->resourceConversionSimulator->execute($state, $playerId, $option),
            $option instanceof PlaceAnnexOptionData => $this->placeAnnexSimulator->execute($state, $playerId, $option),
            $option instanceof PowerOfferOptionData => $this->powerOfferSimulator->execute($state, $playerId, $option),
            $option instanceof ChooseTownOptionData => $this->chooseTownSimulator->execute($state, $playerId, $option),
            $option instanceof WorkshopAfterTerraformingOptionData => $this->workshopAfterTerraformingSimulator->execute($state, $playerId, $option),
            $option instanceof PalaceWaterTownOptionData => $this->palaceWaterTownSimulator->execute($state, $playerId, $option),
            $option instanceof ChoosePalaceOptionData,
            $option instanceof ChoosePalaceRewardOrderOptionData => $this->choosePalaceSimulator->execute($state, $playerId, $option),
            $option instanceof ChooseCompetencyOptionData => $this->chooseCompetencySimulator->execute($state, $playerId, $option),
            $option instanceof PlaceNeutralBuildingOptionData => $this->placeNeutralBuildingSimulator->execute($state, $playerId, $option),
            $option instanceof PlaceBridgeOptionData => $this->placeBridgeSimulator->execute($state, $playerId, $option),
            $option instanceof SkipBridgeOptionData => $this->skipBridgeSimulator->execute($state, $playerId),
            $option instanceof SpendSpadesOptionData => $this->spendSpadesSimulator->execute($state, $playerId, $option),
            $option instanceof PlacePalaceGuildOptionData => $this->placePalaceGuildSimulator->execute($state, $playerId, $option),
            $option instanceof RewardDistributionOptionData => $this->rewardDistributionSimulator->execute($state, $playerId, $option),
            default => throw new DomainException("Симуляция действия {$option->type()->value} ещё не поддерживается."),
        };
    }
}
