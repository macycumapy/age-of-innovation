<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\Research\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\Research\Actions\ApplyChooseCompetencyAction;
use App\Domain\GameEngine\Research\Data\ChooseCompetencyOptionData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use InvalidArgumentException;

final class ChooseCompetencySimulator
{
    public function __construct(private ApplyChooseCompetencyAction $applyChooseCompetency)
    {
    }

    public function execute(GameStateData $state, int $playerId, ChooseCompetencyOptionData $option): GameActionSimulationData
    {
        $simulatedState = $state->deepCopy();
        $player = collect($simulatedState->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applyChooseCompetency->execute($simulatedState, $player, $option->competency);

        return new GameActionSimulationData($simulatedState, $result->nextActivePlayerId);
    }
}
