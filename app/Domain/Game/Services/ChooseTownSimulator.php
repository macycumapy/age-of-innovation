<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyChooseTownAction;
use App\Domain\Game\Data\ChooseTownOptionData;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use InvalidArgumentException;

final class ChooseTownSimulator
{
    public function __construct(private ApplyChooseTownAction $applyChooseTown)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        ChooseTownOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = GameStateData::from($state->toArray());
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applyChooseTown->execute($simulatedState, $simulatedPlayer, $option->townTile);

        return new GameActionSimulationData($simulatedState, $result->nextActiveUserId);
    }
}
