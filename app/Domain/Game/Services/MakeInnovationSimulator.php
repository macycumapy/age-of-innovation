<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyMakeInnovationAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\MakeInnovationOptionData;
use InvalidArgumentException;

final class MakeInnovationSimulator
{
    public function __construct(private ApplyMakeInnovationAction $applyMakeInnovation)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        MakeInnovationOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = GameStateData::from($state->toArray());
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $this->applyMakeInnovation->execute(
            $simulatedState,
            $simulatedPlayer,
            $option->innovation,
            $option->payment->counts(),
        );

        return new GameActionSimulationData($simulatedState, $simulatedPlayer->playerId);
    }
}
