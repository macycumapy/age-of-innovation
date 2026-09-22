<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Data\BookActionOptionData;
use App\Domain\Game\Data\BuildWorkshopOptionData;
use App\Domain\Game\Data\ChooseRoundBonusOptionData;
use App\Domain\Game\Data\ChooseTownOptionData;
use App\Domain\Game\Data\DevelopmentAdvancementOptionData;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\InnovationSpecialActionOptionData;
use App\Domain\Game\Data\MakeInnovationOptionData;
use App\Domain\Game\Data\PaidTerraformingOptionData;
use App\Domain\Game\Data\PalaceActionOptionData;
use App\Domain\Game\Data\PassOptionData;
use App\Domain\Game\Data\PlaceAnnexOptionData;
use App\Domain\Game\Data\PlayerSpecialActionOptionData;
use App\Domain\Game\Data\PowerActionOptionData;
use App\Domain\Game\Data\PowerOfferOptionData;
use App\Domain\Game\Data\ResourceExchangeOptionData;
use App\Domain\Game\Data\SacrificePowerOptionData;
use App\Domain\Game\Data\SendScholarOptionData;
use App\Domain\Game\Data\UpgradeBuildingOptionData;
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
    ) {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        GameActionOption $option,
    ): GameActionSimulationData {
        if ($option instanceof BookActionOptionData) {
            return $this->bookActionSimulator->execute($state, $playerId, $option);
        }

        if ($option instanceof PowerActionOptionData) {
            return $this->powerActionSimulator->execute($state, $playerId, $option);
        }

        if ($option instanceof BuildWorkshopOptionData) {
            return $this->buildWorkshopSimulator->execute($state, $playerId, $option);
        }

        if ($option instanceof UpgradeBuildingOptionData) {
            return $this->upgradeBuildingSimulator->execute($state, $playerId, $option);
        }

        if ($option instanceof PaidTerraformingOptionData) {
            return $this->paidTerraformingSimulator->execute($state, $playerId, $option);
        }

        if ($option instanceof DevelopmentAdvancementOptionData) {
            return $this->developmentAdvancementSimulator->execute($state, $playerId, $option);
        }

        if ($option instanceof SendScholarOptionData) {
            return $this->sendScholarSimulator->execute($state, $playerId, $option);
        }

        if ($option instanceof MakeInnovationOptionData) {
            return $this->makeInnovationSimulator->execute($state, $playerId, $option);
        }

        if ($option instanceof PassOptionData) {
            return $this->passSimulator->execute($state, $playerId, $option);
        }

        if ($option instanceof ChooseRoundBonusOptionData) {
            return $this->chooseRoundBonusSimulator->execute($state, $playerId, $option);
        }

        if ($option instanceof InnovationSpecialActionOptionData) {
            return $this->innovationSpecialActionSimulator->execute($state, $playerId, $option);
        }

        if ($option instanceof PalaceActionOptionData) {
            return $this->palaceActionSimulator->execute($state, $playerId, $option);
        }

        if ($option instanceof PlayerSpecialActionOptionData) {
            return $this->playerSpecialActionSimulator->execute($state, $playerId, $option);
        }

        if ($option instanceof ResourceExchangeOptionData || $option instanceof SacrificePowerOptionData) {
            return $this->resourceConversionSimulator->execute($state, $playerId, $option);
        }

        if ($option instanceof PlaceAnnexOptionData) {
            return $this->placeAnnexSimulator->execute($state, $playerId, $option);
        }

        if ($option instanceof PowerOfferOptionData) {
            return $this->powerOfferSimulator->execute($state, $playerId, $option);
        }

        if ($option instanceof ChooseTownOptionData) {
            return $this->chooseTownSimulator->execute($state, $playerId, $option);
        }

        throw new DomainException("Симуляция действия {$option->type()->value} ещё не поддерживается.");
    }
}
