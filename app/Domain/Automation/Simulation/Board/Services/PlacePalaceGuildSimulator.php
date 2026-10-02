<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\Board\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\Board\Actions\ApplyPlacePalaceGuildAction;
use App\Domain\GameEngine\Board\Data\PlacePalaceGuildOptionData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use InvalidArgumentException;

final class PlacePalaceGuildSimulator
{
    public function __construct(private ApplyPlacePalaceGuildAction $applyPlacePalaceGuild)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        PlacePalaceGuildOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = $state->deepCopy();
        $player = collect($simulatedState->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applyPlacePalaceGuild->execute($simulatedState, $player, $option->hexId);

        return new GameActionSimulationData($simulatedState, $result->nextActivePlayerId);
    }
}
