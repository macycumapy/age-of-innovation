<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyPlaceAnnexAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlaceAnnexOptionData;
use InvalidArgumentException;

final class PlaceAnnexSimulator
{
    public function __construct(private ApplyPlaceAnnexAction $applyPlaceAnnex)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        PlaceAnnexOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = GameStateData::from($state->toArray());
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applyPlaceAnnex->execute($simulatedState, $simulatedPlayer, $option->hexId);

        return new GameActionSimulationData($simulatedState, $result->nextActiveUserId);
    }
}
