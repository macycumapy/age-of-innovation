<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\Economy\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\Economy\Actions\ApplyPowerActionAction;
use App\Domain\GameEngine\Economy\Data\PowerActionOptionData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use InvalidArgumentException;

final class PowerActionSimulator
{
    public function __construct(private ApplyPowerActionAction $applyPowerAction)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        PowerActionOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = $state->deepCopy();
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $this->applyPowerAction->execute(
            $simulatedState,
            $simulatedPlayer,
            $option->action,
            $option->sacrificeAmount,
        );

        return new GameActionSimulationData($simulatedState, $simulatedPlayer->playerId);
    }
}
