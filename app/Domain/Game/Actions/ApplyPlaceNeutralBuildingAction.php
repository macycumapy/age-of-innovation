<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlaceNeutralBuildingResultData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\PendingInteractionType;
use Illuminate\Validation\ValidationException;

final class ApplyPlaceNeutralBuildingAction
{
    public function __construct(
        private FindEligibleNeutralBuildingHexesAction $findEligibleHexes,
        private ApplyBuildingBonusesAction $applyBuildingBonuses,
        private CreateBuildingFollowUpInteractionAction $createBuildingFollowUpInteraction,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
    ) {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        string $hexId,
        BuildingType $buildingType,
    ): PlaceNeutralBuildingResultData {
        $interaction = $state->pendingInteraction;
        $hex = collect($state->board->hexes)->firstWhere('id', $hexId);
        if ($interaction?->type !== PendingInteractionType::PlaceNeutralBuilding
            || $interaction->playerId !== $player->playerId
            || ($interaction->context['reason'] ?? null) === 'starting_competency'
            || ($interaction->context['buildingType'] ?? null) !== $buildingType->value
            || ! $hex instanceof BoardHexStateData
            || ! in_array($hexId, $this->findEligibleHexes->execute($state, $player), true)) {
            throw ValidationException::withMessages(['hex_id' => 'На этой клетке нельзя поставить нейтральное здание.']);
        }

        $toolCost = $hex->terrain->spadesTo($player->homeland) * max(1, 3 - $player->terraformingLevel);
        $player->resources->tools -= $toolCost;
        $hex->terrain = $player->homeland;
        $hex->building = new BuildingStateData($buildingType, $player->playerId, isNeutral: true);
        $state->pendingInteraction = null;
        $bonuses = $this->applyBuildingBonuses->execute($state, $player, $hex, $buildingType);
        $queuedBuiltHexIds = array_values(array_filter(
            (array) ($interaction->context['queuedBuiltHexIds'] ?? []),
            'is_string',
        ));
        $nextActivePlayerId = $buildingType === BuildingType::Tower
            ? $this->createTownChoiceAfterBuilding->execute($state, $player, $hexId, $queuedBuiltHexIds)
            : $this->createBuildingFollowUpInteraction->execute($state, $player, $hexId, $buildingType);

        return new PlaceNeutralBuildingResultData(
            $nextActivePlayerId,
            $toolCost,
            $bonuses['victoryPoints'],
            $bonuses['coins'],
            $bonuses['sources'],
        );
    }
}
