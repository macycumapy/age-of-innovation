<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyBookActionAction;
use App\Domain\Game\Data\BookActionOptionData;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
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
        $simulatedState = GameStateData::from($state->toArray());
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
