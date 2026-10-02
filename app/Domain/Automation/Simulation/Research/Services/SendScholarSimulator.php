<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\Research\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\Research\Actions\ApplySendScholarAction;
use App\Domain\GameEngine\Research\Data\SendScholarOptionData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use InvalidArgumentException;

final class SendScholarSimulator
{
    public function __construct(private ApplySendScholarAction $applySendScholar)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        SendScholarOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = $state->deepCopy();
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $this->applySendScholar->execute($simulatedState, $simulatedPlayer, $option);

        return new GameActionSimulationData($simulatedState, $simulatedPlayer->playerId);
    }
}
