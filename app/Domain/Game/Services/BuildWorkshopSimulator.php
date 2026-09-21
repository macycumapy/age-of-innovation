<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyBuildWorkshopAction;
use App\Domain\Game\Data\BuildWorkshopOptionData;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use InvalidArgumentException;

final class BuildWorkshopSimulator
{
    public function __construct(private ApplyBuildWorkshopAction $applyBuildWorkshop)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        BuildWorkshopOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = GameStateData::from($state->toArray());
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applyBuildWorkshop->execute($simulatedState, $simulatedPlayer, $option->hexId);

        return new GameActionSimulationData($simulatedState, $result->nextActiveUserId);
    }
}
