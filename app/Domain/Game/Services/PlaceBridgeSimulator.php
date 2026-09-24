<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyPlaceBridgeAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlaceBridgeOptionData;
use InvalidArgumentException;

final class PlaceBridgeSimulator
{
    public function __construct(private ApplyPlaceBridgeAction $applyPlaceBridge)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        PlaceBridgeOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = GameStateData::from($state->toArray());
        $player = collect($simulatedState->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applyPlaceBridge->execute(
            $simulatedState,
            $player,
            $option->fromHexId,
            $option->toHexId,
        );

        return new GameActionSimulationData($simulatedState, $result->nextActivePlayerId);
    }
}
