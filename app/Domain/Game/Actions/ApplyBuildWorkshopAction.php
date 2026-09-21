<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BuildingActionResultData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GamePhase;
use Illuminate\Validation\ValidationException;

final class ApplyBuildWorkshopAction
{
    public function __construct(
        private FindReachableLandHexesAction $findReachableLandHexes,
        private ApplyBuildingBonusesAction $applyBuildingBonuses,
        private CreateBuildingFollowUpInteractionAction $createBuildingFollowUpInteraction,
    ) {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        string $hexId,
    ): BuildingActionResultData {
        $hex = collect($state->board->hexes)->firstWhere('id', $hexId);
        $workshopsOnMap = count(array_filter(
            $state->board->hexes,
            static fn (BoardHexStateData $candidate): bool => $candidate->building?->ownerPlayerId === $player->playerId
                && $candidate->building->type === BuildingType::Workshop
                && ! $candidate->building->isNeutral,
        ));

        if ($state->round->phase !== GamePhase::Actions
            || $state->pendingInteraction !== null
            || $state->round->hasTakenMainAction
            || ! $hex instanceof BoardHexStateData
            || ! in_array($hexId, $this->findReachableLandHexes->execute($state, $player), true)
            || $hex->building !== null
            || $hex->terrain !== $player->homeland
            || $player->resources->tools < 1
            || $player->resources->coins < 2
            || $workshopsOnMap >= BuildingType::Workshop->supplyLimit()) {
            throw ValidationException::withMessages(['building' => 'Дом нельзя построить на выбранной клетке.']);
        }

        $player->resources->tools--;
        $player->resources->coins -= 2;
        $hex->building = new BuildingStateData(BuildingType::Workshop, $player->playerId);
        $state->round->hasTakenMainAction = true;
        $bonuses = $this->applyBuildingBonuses->execute($state, $player, $hex, BuildingType::Workshop);
        $nextActiveUserId = $this->createBuildingFollowUpInteraction->execute(
            $state,
            $player,
            $hexId,
            BuildingType::Workshop,
        );

        return new BuildingActionResultData(
            $nextActiveUserId,
            null,
            BuildingType::Workshop,
            1,
            2,
            $bonuses['victoryPoints'],
            $bonuses['coins'],
            $bonuses['sources'],
        );
    }
}
