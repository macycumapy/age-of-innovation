<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\PendingInteractionType;

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

            return $playerState->userId;
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

            return $playerState->userId;
        }

        return $this->createTownChoiceAfterBuilding->execute(
            $state,
            $playerState,
            $builtHexId,
        );
    }
}
