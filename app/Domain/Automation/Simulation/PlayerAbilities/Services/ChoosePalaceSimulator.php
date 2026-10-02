<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\PlayerAbilities\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\PlayerAbilities\Actions\ApplyChoosePalaceAction;
use App\Domain\GameEngine\PlayerAbilities\Data\ChoosePalaceOptionData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use InvalidArgumentException;

final class ChoosePalaceSimulator
{
    public function __construct(private ApplyChoosePalaceAction $applyChoosePalace)
    {
    }

    public function execute(GameStateData $state, int $playerId, ChoosePalaceOptionData $option): GameActionSimulationData
    {
        $simulatedState = $state->deepCopy();
        $player = collect($simulatedState->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applyChoosePalace->execute($simulatedState, $player, $option->palace);

        return new GameActionSimulationData($simulatedState, $result->nextActivePlayerId);
    }
}
