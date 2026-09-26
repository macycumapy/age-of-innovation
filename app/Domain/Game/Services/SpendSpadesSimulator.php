<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplySpendSpadesAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\SpendSpadesOptionData;
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
