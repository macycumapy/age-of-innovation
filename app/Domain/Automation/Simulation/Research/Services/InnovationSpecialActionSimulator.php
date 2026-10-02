<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\Research\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\Research\Actions\ApplyInnovationSpecialAction;
use App\Domain\GameEngine\Research\Data\InnovationSpecialActionOptionData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use InvalidArgumentException;

final class InnovationSpecialActionSimulator
{
    public function __construct(private ApplyInnovationSpecialAction $applyInnovationSpecialAction)
    {
    }

    public function execute(GameStateData $state, int $playerId, InnovationSpecialActionOptionData $option): GameActionSimulationData
    {
        $simulatedState = $state->deepCopy();
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $this->applyInnovationSpecialAction->execute($simulatedState, $simulatedPlayer, $option);

        return new GameActionSimulationData($simulatedState, $simulatedPlayer->playerId);
    }
}
