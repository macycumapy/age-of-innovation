<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyPaidTerraformingAction;
use App\Domain\Game\Actions\ApplySpendSpadesAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PaidTerraformingOptionData;
use App\Domain\Game\Data\SpendSpadesOptionData;
use InvalidArgumentException;

final class PaidTerraformingSimulator
{
    public function __construct(
        private ApplyPaidTerraformingAction $applyPaidTerraforming,
        private ApplySpendSpadesAction $applySpendSpades,
    ) {
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
        $spadesToSpend = (int) ($simulatedState->pendingInteraction?->context['spadesToSpend'] ?? 0);

        $result = $this->applySpendSpades->execute(
            $simulatedState,
            $simulatedPlayer,
            new SpendSpadesOptionData($option->hexId, $spadesToSpend),
            requireActionPhase: false,
        );

        return new GameActionSimulationData($simulatedState, $result->nextActivePlayerId);
    }
}
