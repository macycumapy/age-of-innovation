<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplySkipBridgeAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use InvalidArgumentException;

final class SkipBridgeSimulator
{
    public function __construct(private ApplySkipBridgeAction $applySkipBridge)
    {
    }

    public function execute(GameStateData $state, int $playerId): GameActionSimulationData
    {
        $simulatedState = $state->deepCopy();
        $player = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        return new GameActionSimulationData(
            $simulatedState,
            $this->applySkipBridge->execute($simulatedState, $player),
        );
    }
}
