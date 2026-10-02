<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\Economy\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\Economy\Actions\ApplyBookActionAction;
use App\Domain\GameEngine\Economy\Data\BookActionOptionData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use InvalidArgumentException;

final class BookActionSimulator
{
    public function __construct(private ApplyBookActionAction $applyBookAction)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        BookActionOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = $state->deepCopy();
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applyBookAction->execute(
            $simulatedState,
            $simulatedPlayer,
            $option->action,
            $option->payment->counts(),
            $option->discipline,
            $option->hexId,
        );

        return new GameActionSimulationData($simulatedState, $result->nextActivePlayerId);
    }
}
