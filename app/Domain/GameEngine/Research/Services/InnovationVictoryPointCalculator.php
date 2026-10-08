<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Services;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Services\BridgeSupply;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class InnovationVictoryPointCalculator
{
    public function __construct(private BridgeSupply $bridgeSupply)
    {
    }

    public function execute(GameStateData $state, GamePlayerStateData $player, Innovation $innovation): int
    {
        return match ($innovation) {
            Innovation::SewageSystem => $this->buildingCount($state, $player, BuildingType::Workshop) * 2,
            Innovation::Architecture => 10,
            Innovation::Library => $this->twoHighestKnowledgeLevels($player),
            Innovation::LeagueOfCities => count($player->townTileIds) * 5,
            Innovation::Telecommunication => $this->thresholdVictoryPoints($this->settlementAreaCount($state, $player), 4, 5, 6),
            Innovation::Steel => $this->thresholdVictoryPoints(BridgeSupply::SUPPLY_LIMIT - $this->bridgeSupply->remaining($state, $player), 1, 2, 3),
            Innovation::Census => $this->thresholdVictoryPoints($this->buildingCount($state, $player), 7, 9, 11),
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

    private function buildingCount(GameStateData $state, GamePlayerStateData $player, ?BuildingType $type = null): int
    {
        return count(array_filter(
            $state->board->hexes,
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->playerId
                && ($type === null || $hex->building->type === $type),
        ));
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

    private function settlementAreaCount(GameStateData $state, GamePlayerStateData $player): int
    {
        $ownedHexIds = [];
        $hexesById = [];

        foreach ($state->board->hexes as $hex) {
            $hexesById[$hex->id] = $hex;
            if ($hex->building?->ownerPlayerId === $player->playerId) {
                $ownedHexIds[$hex->id] = true;
            }
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
                if (isset($visited[$hexId])) {
                    continue;
                }

                $visited[$hexId] = true;
                array_push($frontier, ...$connections[$hexId]);
            }
        }

        return $areaCount;
    }
}
