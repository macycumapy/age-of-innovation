<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Towns\Actions;

use App\Domain\GameEngine\Board\Actions\FindEligibleTerraformHexesAction;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;

final class StartLizardTownBonusAction
{
    public function __construct(
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
        private ContinuePalaceTownChoicesAction $continuePalaceTownChoices,
    ) {
    }

    public function execute(GameStateData $state, GamePlayerStateData $playerState, string $builtHexId = ''): bool
    {
        $eligibleHexIds = $this->findEligibleTerraformHexes->execute(
            $state,
            $playerState,
            $playerState->homeland,
        );

        if ($eligibleHexIds === []) {
            $state->pendingInteraction = null;
            if ($builtHexId !== '') {
                $this->createTownChoiceAfterBuilding->execute($state, $playerState, $builtHexId, powerOffersResolved: true);
            }

            $this->continuePalaceTownChoices->execute($state, $playerState);

            return false;
        }

        $playerState->unassignedSpades++;
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::SpendSpades,
            $playerState->playerId,
            $eligibleHexIds,
            [
                'phase' => GamePhase::Actions->value,
                'spadeCount' => 1,
                'remainingSpades' => 1,
                'targetTerrain' => $playerState->homeland->value,
                'lizardFreeWorkshop' => true,
                ...($builtHexId !== '' ? ['builtHexId' => $builtHexId, 'powerOffersResolved' => true] : []),
            ],
        );

        return true;
    }
}
