<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyInnovationSpecialAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\InnovationSpecialActionOptionData;
use InvalidArgumentException;

final class InnovationSpecialActionSimulator
{
    public function __construct(private ApplyInnovationSpecialAction $applyInnovationSpecialAction)
    {
    }

    public function execute(GameStateData $state, int $playerId, InnovationSpecialActionOptionData $option): GameActionSimulationData
    {
        $simulatedState = $state->deepCopy();
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $this->applyInnovationSpecialAction->execute($simulatedState, $simulatedPlayer, $option);

        return new GameActionSimulationData($simulatedState, $simulatedPlayer->playerId);
    }
}
