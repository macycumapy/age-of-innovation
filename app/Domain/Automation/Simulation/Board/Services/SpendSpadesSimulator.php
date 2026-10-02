<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\Board\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\Board\Actions\ApplySpendSpadesAction;
use App\Domain\GameEngine\Board\Data\SpendSpadesOptionData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use InvalidArgumentException;

final class SpendSpadesSimulator
{
    public function __construct(private ApplySpendSpadesAction $applySpendSpades)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        SpendSpadesOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = $state->deepCopy();
        $player = collect($simulatedState->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applySpendSpades->execute($simulatedState, $player, $option);

        return new GameActionSimulationData($simulatedState, $result->nextActivePlayerId);
    }
}
