<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BridgeStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Innovation;
use App\Domain\Game\Enums\KnowledgeDiscipline;

class ApplyInnovationRewardAction
{
    public function __construct(
        private AdvanceKnowledgeAction $advanceKnowledge,
        private AdvanceDevelopmentTrackAction $advanceDevelopmentTrack,
        private ApplyDevelopmentTrackRoundScoringAction $applyDevelopmentTrackRoundScoring,
    ) {
    }

    /** @return array{victoryPoints: int, scholars: int, power: int, books: int, developmentTrackBooks: int, knowledgeSteps: int, shippingSteps: int, terraformingSteps: int, gainedPower: int} */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        Innovation $innovation,
    ): array {
        $innovationVictoryPoints = $this->victoryPoints($state, $player, $innovation);
        $reward = [
            'victoryPoints' => $innovationVictoryPoints,
            'scholars' => 0,
            'power' => 0,
            'books' => 0,
            'developmentTrackBooks' => 0,
            'knowledgeSteps' => 0,
            'shippingSteps' => 0,
            'terraformingSteps' => 0,
            'gainedPower' => 0,
        ];

        if ($innovation === Innovation::DeusExMachina) {
            $player->resources->books->unassigned++;
            $reward['books'] = 1;

            foreach (KnowledgeDiscipline::cases() as $discipline) {
                $levelBefore = $player->knowledge->{$discipline->value};
                $reward['gainedPower'] += $this->advanceKnowledge->execute($state, $player, $discipline, 1);
                $reward['knowledgeSteps'] += $player->knowledge->{$discipline->value} - $levelBefore;
            }
        }

        if ($innovation === Innovation::Architecture) {
            $reward['knowledgeSteps'] = $this->distinctBuildingTypeCount($state, $player);
            $player->knowledge->unassignedSteps += $reward['knowledgeSteps'];
        }

        if ($innovation === Innovation::SteamEngine) {
            $scholarsBefore = $player->resources->scholars;
            $player->resources->scholars = min($player->scholarPoolSize, $scholarsBefore + 1);
            $reward['scholars'] = $player->resources->scholars - $scholarsBefore;
            $shippingReward = $this->advanceDevelopmentTrack->advanceShipping($player);
            $terraformingReward = $this->advanceDevelopmentTrack->advanceTerraforming($player);
            $reward['shippingSteps'] = $shippingReward['steps'];
            $reward['terraformingSteps'] = $terraformingReward['steps'];
            $reward['developmentTrackBooks'] = $shippingReward['books'] + $terraformingReward['books'];
            $reward['books'] += $reward['developmentTrackBooks'];
            $reward['victoryPoints'] += $shippingReward['victoryPoints'] + $terraformingReward['victoryPoints'];
            $reward['victoryPoints'] += $this->applyDevelopmentTrackRoundScoring->execute(
                $state,
                $player,
                $shippingReward['steps'] + $terraformingReward['steps'],
            );
        }

        if ($innovation === Innovation::Palace) {
            $player->resources->power->bowlThree += 2;
            $reward['power'] = 2;
        }

        $player->victoryPoints += $innovationVictoryPoints;

        return $reward;
    }

    private function victoryPoints(
        GameStateData $state,
        GamePlayerStateData $player,
        Innovation $innovation,
    ): int {
        return match ($innovation) {
            Innovation::SewageSystem => $this->buildingCount($state, $player, BuildingType::Workshop) * 2,
            Innovation::Architecture => 10,
            Innovation::Library => $this->twoHighestKnowledgeLevels($player),
            Innovation::LeagueOfCities => count($player->townTileIds) * 5,
            Innovation::Telecommunication => $this->thresholdVictoryPoints(
                $this->settlementAreaCount($state, $player),
                4,
                5,
                6,
            ),
            Innovation::Steel => $this->thresholdVictoryPoints(
                $this->eligibleBridgeCount($state, $player),
                1,
                2,
                3,
            ),
            Innovation::Census => $this->thresholdVictoryPoints(
                $this->buildingCount($state, $player),
                7,
                9,
                11,
            ),
            Innovation::Science => $this->buildingCount($state, $player, BuildingType::School) * 5,
            Innovation::Monument => 7,
            default => 0,
        };
    }

    private function thresholdVictoryPoints(int $count, int $first, int $second, int $third): int
    {
        return match (true) {
            $count >= $third => 18,
            $count >= $second => 12,
            $count >= $first => 8,
            default => 0,
        };
    }

    private function buildingCount(
        GameStateData $state,
        GamePlayerStateData $player,
        ?BuildingType $type = null,
    ): int {
        return count(array_filter(
            $state->board->hexes,
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->playerId
                && ($type === null || $hex->building->type === $type),
        ));
    }

    private function distinctBuildingTypeCount(GameStateData $state, GamePlayerStateData $player): int
    {
        $types = [];

        foreach ($state->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId === $player->playerId) {
                $types[$hex->building->type->value] = true;
            }
        }

        return count($types);
    }

    private function twoHighestKnowledgeLevels(GamePlayerStateData $player): int
    {
        $levels = array_map(
            static fn (KnowledgeDiscipline $discipline): int => $player->knowledge->{$discipline->value},
            KnowledgeDiscipline::cases(),
        );
        rsort($levels);

        return $levels[0] + $levels[1];
    }

    private function eligibleBridgeCount(GameStateData $state, GamePlayerStateData $player): int
    {
        $ownedBuildingHexIds = array_fill_keys($this->ownedBuildingHexIds($state, $player), true);

        return count(array_filter(
            $state->board->bridges,
            static fn (BridgeStateData $bridge): bool => $bridge->ownerPlayerId === $player->playerId
                && isset($ownedBuildingHexIds[$bridge->fromHexId], $ownedBuildingHexIds[$bridge->toHexId]),
        ));
    }

    private function settlementAreaCount(GameStateData $state, GamePlayerStateData $player): int
    {
        $ownedHexIds = array_fill_keys($this->ownedBuildingHexIds($state, $player), true);
        $hexesById = [];

        foreach ($state->board->hexes as $hex) {
            $hexesById[$hex->id] = $hex;
        }

        $connections = [];

        foreach (array_keys($ownedHexIds) as $hexId) {
            $connections[$hexId] = array_values(array_filter(
                $hexesById[$hexId]->adjacentHexIds,
                static fn (string $adjacentHexId): bool => isset($ownedHexIds[$adjacentHexId]),
            ));
        }

        foreach ($state->board->bridges as $bridge) {
            if ($bridge->ownerPlayerId !== $player->playerId
                || ! isset($ownedHexIds[$bridge->fromHexId], $ownedHexIds[$bridge->toHexId])) {
                continue;
            }

            $connections[$bridge->fromHexId][] = $bridge->toHexId;
            $connections[$bridge->toHexId][] = $bridge->fromHexId;
        }

        $visited = [];
        $areaCount = 0;

        foreach (array_keys($ownedHexIds) as $startingHexId) {
            if (isset($visited[$startingHexId])) {
                continue;
            }

            $areaCount++;
            $frontier = [$startingHexId];

            while ($frontier !== []) {
                $hexId = array_pop($frontier);

                if ($hexId === null || isset($visited[$hexId])) {
                    continue;
                }

                $visited[$hexId] = true;
                array_push($frontier, ...$connections[$hexId]);
            }
        }

        return $areaCount;
    }

    /** @return list<string> */
    private function ownedBuildingHexIds(GameStateData $state, GamePlayerStateData $player): array
    {
        return array_values(array_map(
            static fn (BoardHexStateData $hex): string => $hex->id,
            array_filter(
                $state->board->hexes,
                static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->playerId,
            ),
        ));
    }
}
