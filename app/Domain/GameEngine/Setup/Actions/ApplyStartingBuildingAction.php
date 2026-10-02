<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Setup\Actions;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\Setup\Data\StartingBuildingResultData;
use App\Domain\GameEngine\Setup\Services\StartingBuildingOrderFinder;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Illuminate\Validation\ValidationException;

final class ApplyStartingBuildingAction
{
    public function __construct(
        private StartingBuildingOrderFinder $startingBuildingOrderFinder,
        private ResolveCompletedStartingSetupAction $resolveCompletedStartingSetup,
    ) {
    }

    public function execute(GameStateData $state, GamePlayerStateData $player, string $hexId): StartingBuildingResultData
    {
        if ($state->round->phase !== GamePhase::Setup
            || count($state->planningSelections) !== count($state->turnOrder)
            || $state->pendingInteraction !== null) {
            throw ValidationException::withMessages(['game' => 'Сейчас нельзя установить стартовый дом.']);
        }

        $hex = collect($state->board->hexes)->firstWhere('id', $hexId);
        if (! $hex instanceof BoardHexStateData) {
            throw ValidationException::withMessages(['hex_id' => 'Ячейка карты не найдена.']);
        }

        $isStaged = $state->pendingStartingBuildingHexId === $hexId
            && $hex->building?->ownerPlayerId === $player->playerId;
        if (! $isStaged) {
            if ($state->pendingStartingBuildingHexId !== null
                || $hex->building !== null
                || $hex->terrain !== $player->homeland) {
                throw ValidationException::withMessages(['hex_id' => 'Выберите свободную ячейку родной местности.']);
            }

            $ownedBuildingCount = collect($state->board->hexes)->filter(
                static fn (BoardHexStateData $boardHex): bool => $boardHex->building?->ownerPlayerId === $player->playerId,
            )->count();
            $buildingType = match (true) {
                $player->faction === Faction::Monks => BuildingType::University,
                $player->faction === Faction::Omar && $ownedBuildingCount >= 2 => BuildingType::Tower,
                default => BuildingType::Workshop,
            };
            $hex->building = new BuildingStateData(
                $buildingType,
                $player->playerId,
                isNeutral: $buildingType === BuildingType::Tower,
            );
        }

        $buildingType = $hex->building->type;

        $state->pendingStartingBuildingHexId = null;
        $state->startingBuildingTurnIndex++;
        $placementOrder = $this->startingBuildingOrderFinder->execute($state);
        $hasFinishedOwnStartingBuildings = ! in_array(
            $player->playerId,
            array_slice($placementOrder, $state->startingBuildingTurnIndex),
            true,
        );

        if (in_array($player->faction, [Faction::Inventors, Faction::Monks], true) && $hasFinishedOwnStartingBuildings) {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::ChooseCompetency,
                $player->playerId,
                array_values(array_unique(array_filter(
                    $state->availableCompetencyIds,
                    static fn (string $competencyId): bool => ! in_array(
                        $competencyId,
                        $player->competencyIds,
                        true,
                    ),
                ))),
            );

            return new StartingBuildingResultData($hexId, $buildingType, $player->playerId, GamePhase::Setup, []);
        }

        if ($state->startingBuildingTurnIndex < count($placementOrder)) {
            return new StartingBuildingResultData(
                $hexId,
                $buildingType,
                $placementOrder[$state->startingBuildingTurnIndex],
                GamePhase::Setup,
                [],
            );
        }

        $resolution = $this->resolveCompletedStartingSetup->execute($state);

        return new StartingBuildingResultData(
            $hexId,
            $buildingType,
            $resolution->nextActivePlayerId,
            $resolution->phase,
            $resolution->incomeReceipts,
        );
    }
}
