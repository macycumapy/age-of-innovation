<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BuildingActionResultData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Services\BuildingAdjacencyChecker;
use Illuminate\Validation\ValidationException;

final class ApplyUpgradeBuildingAction
{
    public function __construct(
        private ApplyBuildingBonusesAction $applyBuildingBonuses,
        private CreateBuildingFollowUpInteractionAction $createBuildingFollowUpInteraction,
    ) {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        string $hexId,
        BuildingType $target,
    ): BuildingActionResultData {
        $hex = collect($state->board->hexes)->firstWhere('id', $hexId);

        if (! $state->round->phase->isActionPhase()
            || $state->pendingInteraction !== null
            || $state->round->hasTakenMainAction
            || ! $hex instanceof BoardHexStateData
            || $hex->building === null
            || $hex->building->ownerPlayerId !== $player->playerId
            || $hex->building->isNeutral
            || ! in_array($target, $hex->building->type->upgradeOptions(), true)) {
            throw ValidationException::withMessages(['building' => 'Это здание нельзя улучшить выбранным способом.']);
        }

        $hasAdjacentOpponent = BuildingAdjacencyChecker::hasOpponent($state->board, $hex, $player->playerId);
        $cost = $hex->building->type->upgradeCostTo($target, $hasAdjacentOpponent);
        $targetBuildingsOnMap = count(array_filter(
            $state->board->hexes,
            static fn (BoardHexStateData $candidate): bool => $candidate->building?->ownerPlayerId === $player->playerId
                && $candidate->building->type === $target
                && ! $candidate->building->isNeutral,
        ));

        if ($player->resources->tools < $cost['tools']
            || $player->resources->coins < $cost['coins']
            || $targetBuildingsOnMap >= $target->supplyLimit()) {
            throw ValidationException::withMessages(['building' => 'Не хватает ресурсов или свободной фигурки здания.']);
        }

        $source = $hex->building->type;
        $player->resources->tools -= $cost['tools'];
        $player->resources->coins -= $cost['coins'];
        $hex->building->type = $target;
        $state->round->hasTakenMainAction = true;
        $bonuses = $this->applyBuildingBonuses->execute($state, $player, $hex, $target);
        $nextActiveUserId = $this->createBuildingFollowUpInteraction->execute($state, $player, $hexId, $target);

        return new BuildingActionResultData(
            $nextActiveUserId,
            $source,
            $target,
            $cost['tools'],
            $cost['coins'],
            $bonuses['victoryPoints'],
            $bonuses['coins'],
            $bonuses['sources'],
        );
    }
}
