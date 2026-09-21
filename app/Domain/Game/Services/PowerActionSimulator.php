<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyPowerActionAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PowerActionOptionData;
use InvalidArgumentException;

final class PowerActionSimulator
{
    public function __construct(private ApplyPowerActionAction $applyPowerAction)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        PowerActionOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = GameStateData::from($state->toArray());
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $this->applyPowerAction->execute(
            $simulatedState,
            $simulatedPlayer,
            $option->action,
            $option->sacrificeAmount,
        );

        return new GameActionSimulationData($simulatedState, $simulatedPlayer->userId);
    }
}
