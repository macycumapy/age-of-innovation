<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyWorkshopAfterTerraformingAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\WorkshopAfterTerraformingOptionData;
use InvalidArgumentException;

final class WorkshopAfterTerraformingSimulator
{
    public function __construct(private ApplyWorkshopAfterTerraformingAction $applyWorkshopAfterTerraforming)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        WorkshopAfterTerraformingOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = GameStateData::from($state->toArray());
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applyWorkshopAfterTerraforming->execute(
            $simulatedState,
            $simulatedPlayer,
            $option->build,
            $option->hexId,
        );

        return new GameActionSimulationData($simulatedState, $result->nextActiveUserId);
    }
}
