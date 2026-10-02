<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\GameEngine\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\Automation\Simulation\Services\SimulationGamePlayerFactory;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Actions\ApplyChooseRoundBonusAction;
use App\Domain\GameEngine\Turns\Data\ChooseRoundBonusOptionData;
use InvalidArgumentException;

final class ChooseRoundBonusSimulator
{
    public function __construct(
        private SimulationGamePlayerFactory $gamePlayerFactory,
        private ApplyChooseRoundBonusAction $applyChooseRoundBonus,
    ) {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        ChooseRoundBonusOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = $state->deepCopy();
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applyChooseRoundBonus->execute(
            $simulatedState,
            $simulatedPlayer,
            $option,
            $this->gamePlayerFactory->create($simulatedState),
        );

        return new GameActionSimulationData(
            $simulatedState,
            $result->nextActivePlayerId ?? $simulatedPlayer->playerId,
        );
    }
}
