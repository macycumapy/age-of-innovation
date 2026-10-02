<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\Towns\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Towns\Actions\ApplyChooseTownAction;
use App\Domain\GameEngine\Towns\Data\ChooseTownOptionData;
use InvalidArgumentException;

final class ChooseTownSimulator
{
    public function __construct(private ApplyChooseTownAction $applyChooseTown)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        ChooseTownOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = $state->deepCopy();
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applyChooseTown->execute(
            $simulatedState,
            $simulatedPlayer,
            $option->townTile,
            captureCheckpoint: false,
        );

        return new GameActionSimulationData($simulatedState, $result->nextActivePlayerId);
    }
}
