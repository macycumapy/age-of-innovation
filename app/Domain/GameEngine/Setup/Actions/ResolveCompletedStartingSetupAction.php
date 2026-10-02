<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Setup\Actions;

use App\Domain\GameEngine\Board\Actions\FindEligibleTerraformHexesAction;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Setup\Data\StartingSetupResolutionData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Actions\ResolveIncomePhaseAction;
use App\Domain\GameEngine\Turns\Enums\GamePhase;

final class ResolveCompletedStartingSetupAction
{
    public function __construct(
        private CreateDesertStartingSpadeInteractionAction $createDesertStartingSpadeInteraction,
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private ResolveIncomePhaseAction $resolveIncomePhase,
    ) {
    }

    public function execute(GameStateData $state): StartingSetupResolutionData
    {
        foreach ($state->players as $competencyPlayer) {
            if ($competencyPlayer->unassignedSpades >= 2
                && in_array(Competency::Competency05->value, $competencyPlayer->competencyIds, true)) {
                $eligibleHexIds = $this->findEligibleTerraformHexes->execute(
                    $state,
                    $competencyPlayer,
                    $competencyPlayer->homeland,
                );

                if ($eligibleHexIds !== []) {
                    $state->pendingInteraction = new PendingInteractionData(
                        PendingInteractionType::SpendSpades,
                        $competencyPlayer->playerId,
                        $eligibleHexIds,
                        [
                            'spadeCount' => 2,
                            'remainingSpades' => 2,
                            'targetTerrain' => $competencyPlayer->homeland->value,
                        ],
                    );

                    return new StartingSetupResolutionData($competencyPlayer->playerId, GamePhase::Setup, []);
                }
            }
        }

        foreach ($state->players as $desertPlayer) {
            if ($this->createDesertStartingSpadeInteraction->execute($state, $desertPlayer)) {
                return new StartingSetupResolutionData($desertPlayer->playerId, GamePhase::Setup, []);
            }
        }

        $state->round->phase = GamePhase::Income;
        $state->round->incomeTurnIndex = 0;
        $state->round->incomeOrder = [];
        $state->round->incomeReceipts = [];

        [$nextPlayerState, $phase, $incomeReceipts] = $this->resolveIncomePhase->execute($state);
        return new StartingSetupResolutionData($nextPlayerState->playerId, $phase, $incomeReceipts);
    }
}
