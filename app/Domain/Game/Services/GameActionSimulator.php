<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Data\BookActionOptionData;
use App\Domain\Game\Data\BuildWorkshopOptionData;
use App\Domain\Game\Data\ChooseCompetencyOptionData;
use App\Domain\Game\Data\ChoosePalaceOptionData;
use App\Domain\Game\Data\ChooseRoundBonusOptionData;
use App\Domain\Game\Data\ChooseTownOptionData;
use App\Domain\Game\Data\DevelopmentAdvancementOptionData;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\InnovationSpecialActionOptionData;
use App\Domain\Game\Data\MakeInnovationOptionData;
use App\Domain\Game\Data\PaidTerraformingOptionData;
use App\Domain\Game\Data\PalaceActionOptionData;
use App\Domain\Game\Data\PalaceWaterTownOptionData;
use App\Domain\Game\Data\PassOptionData;
use App\Domain\Game\Data\PlaceAnnexOptionData;
use App\Domain\Game\Data\PlaceBridgeOptionData;
use App\Domain\Game\Data\PlaceNeutralBuildingOptionData;
use App\Domain\Game\Data\PlacePalaceGuildOptionData;
use App\Domain\Game\Data\PlayerSpecialActionOptionData;
use App\Domain\Game\Data\PowerActionOptionData;
use App\Domain\Game\Data\PowerOfferOptionData;
use App\Domain\Game\Data\ResourceExchangeOptionData;
use App\Domain\Game\Data\RewardDistributionOptionData;
use App\Domain\Game\Data\SacrificePowerOptionData;
use App\Domain\Game\Data\SendScholarOptionData;
use App\Domain\Game\Data\SpendSpadesOptionData;
use App\Domain\Game\Data\UpgradeBuildingOptionData;
use App\Domain\Game\Data\WorkshopAfterTerraformingOptionData;
use DomainException;

final class GameActionSimulator
{
    public function __construct(
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
            $option instanceof ChoosePalaceOptionData => $this->choosePalaceSimulator->execute($state, $playerId, $option),
            $option instanceof ChooseCompetencyOptionData => $this->chooseCompetencySimulator->execute($state, $playerId, $option),
            $option instanceof PlaceNeutralBuildingOptionData => $this->placeNeutralBuildingSimulator->execute($state, $playerId, $option),
            $option instanceof PlaceBridgeOptionData => $this->placeBridgeSimulator->execute($state, $playerId, $option),
            $option instanceof SpendSpadesOptionData => $this->spendSpadesSimulator->execute($state, $playerId, $option),
            $option instanceof PlacePalaceGuildOptionData => $this->placePalaceGuildSimulator->execute($state, $playerId, $option),
            $option instanceof RewardDistributionOptionData => $this->rewardDistributionSimulator->execute($state, $playerId, $option),
            default => throw new DomainException("Симуляция действия {$option->type()->value} ещё не поддерживается."),
        };
    }
}
