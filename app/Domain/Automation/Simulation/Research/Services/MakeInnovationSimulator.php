<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\Research\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\Research\Actions\ApplyMakeInnovationAction;
use App\Domain\GameEngine\Research\Data\MakeInnovationOptionData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use InvalidArgumentException;

final class MakeInnovationSimulator
{
    public function __construct(private ApplyMakeInnovationAction $applyMakeInnovation)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        MakeInnovationOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = $state->deepCopy();
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $this->applyMakeInnovation->execute(
            $simulatedState,
            $simulatedPlayer,
            $option->innovation,
            $option->payment->counts(),
        );

        return new GameActionSimulationData($simulatedState, $simulatedPlayer->playerId);
    }
}
