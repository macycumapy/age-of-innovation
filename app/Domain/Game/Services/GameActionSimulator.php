<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Data\BookActionOptionData;
use App\Domain\Game\Data\BuildWorkshopOptionData;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PowerActionOptionData;
use DomainException;

final class GameActionSimulator
{
    public function __construct(
        private BookActionSimulator $bookActionSimulator,
        private PowerActionSimulator $powerActionSimulator,
        private BuildWorkshopSimulator $buildWorkshopSimulator,
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

        throw new DomainException("Симуляция действия {$option->type()} ещё не поддерживается.");
    }
}
