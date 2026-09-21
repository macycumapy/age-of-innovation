<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Data\BookActionOptionData;
use App\Domain\Game\Data\BuildWorkshopOptionData;
use App\Domain\Game\Data\DevelopmentAdvancementOptionData;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\MakeInnovationOptionData;
use App\Domain\Game\Data\PaidTerraformingOptionData;
use App\Domain\Game\Data\PassOptionData;
use App\Domain\Game\Data\PowerActionOptionData;
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

        throw new DomainException("Симуляция действия {$option->type()} ещё не поддерживается.");
    }
}
