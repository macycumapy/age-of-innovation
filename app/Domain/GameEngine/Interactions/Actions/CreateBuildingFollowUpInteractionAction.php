<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Interactions\Actions;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Towns\Actions\CreateTownChoiceAfterBuildingAction;

final class CreateBuildingFollowUpInteractionAction
{
    public function __construct(
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
    ) {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $playerState,
        string $builtHexId,
        BuildingType $buildingType,
    ): int {
        $builtHex = collect($state->board->hexes)->firstWhere('id', $builtHexId);
        $isNeutralUniversity = $buildingType === BuildingType::University
            && $builtHex instanceof BoardHexStateData
            && $builtHex->building?->isNeutral === true;
        $isNeutralPalace = $buildingType === BuildingType::Palace
            && $builtHex instanceof BoardHexStateData
            && $builtHex->building?->isNeutral === true;

        if (! $isNeutralPalace && $buildingType === BuildingType::Palace) {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::ChoosePalace,
                $playerState->playerId,
                $state->availablePalaceIds,
                [
                    'reason' => 'building',
                    'builtHexId' => $builtHexId,
                ],
            );

            return $playerState->playerId;
        }

        if (! $isNeutralUniversity
            && in_array($buildingType, [BuildingType::School, BuildingType::University], true)) {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::ChooseCompetency,
                $playerState->playerId,
                array_values(array_unique(array_filter(
                    $state->availableCompetencyIds,
                    static fn (string $competencyId): bool => ! in_array(
                        $competencyId,
                        $playerState->competencyIds,
                        true,
                    ),
                ))),
                [
                    'reason' => 'building',
                    'builtHexId' => $builtHexId,
                    'buildingType' => $buildingType->value,
                ],
            );

            return $playerState->playerId;
        }

        return $this->createTownChoiceAfterBuilding->execute(
            $state,
            $playerState,
            $builtHexId,
        );
    }
}
