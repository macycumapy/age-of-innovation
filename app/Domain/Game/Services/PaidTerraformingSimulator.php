<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyPaidTerraformingAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PaidTerraformingOptionData;
use InvalidArgumentException;

final class PaidTerraformingSimulator
{
    public function __construct(private ApplyPaidTerraformingAction $applyPaidTerraforming)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        PaidTerraformingOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = GameStateData::from($state->toArray());
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $this->applyPaidTerraforming->execute($simulatedState, $simulatedPlayer, $option);

        return new GameActionSimulationData($simulatedState, $simulatedPlayer->userId);
    }
}
