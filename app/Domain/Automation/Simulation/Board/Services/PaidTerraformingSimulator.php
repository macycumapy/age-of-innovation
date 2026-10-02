<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\Board\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\Board\Actions\ApplyPaidTerraformingAction;
use App\Domain\GameEngine\Board\Actions\ApplySpendSpadesAction;
use App\Domain\GameEngine\Board\Data\PaidTerraformingOptionData;
use App\Domain\GameEngine\Board\Data\SpendSpadesOptionData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
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
        $simulatedState = $state->deepCopy();
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
