<?php

declare(strict_types=1);

namespace App\Domain\Game\Factories;

use App\Domain\Game\Data\BookSupplyData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\KnowledgeStateData;
use App\Domain\Game\Data\PlanningBundleData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\PowerBowlsStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\TerrainType;
use App\Models\GamePlayer;

final class GamePlayerStateFactory
{
    public function create(GamePlayer $player, PlanningBundleData $bundle): GamePlayerStateData
    {
        $resources = new PlayerResourcesData(
            coins: 15,
            tools: 3,
            scholars: 0,
            books: new BookSupplyData(
                unassigned: $bundle->homeland === TerrainType::Wasteland ? 1 : 0,
            ),
            power: $this->power($bundle->homeland),
        );
        $knowledge = $this->knowledge($bundle);

        if ($bundle->homeland === TerrainType::Wasteland) {
            $resources->tools++;
        }

        if ($bundle->homeland === TerrainType::Swamp) {
            $resources->scholars++;
        }

        if (in_array($bundle->faction, [Faction::Goblins, Faction::Psychics], true)) {
            $resources->tools++;
        }

        return new GamePlayerStateData(
            playerId: $player->id,
            userId: $player->user_id,
            color: $this->colorFor($bundle->homeland),
            faction: $bundle->faction,
            homeland: $bundle->homeland,
            roundBonus: $bundle->roundBonus,
            resources: $resources,
            knowledge: $knowledge,
            shippingLevel: $bundle->homeland === TerrainType::Lake ? 1 : 0,
        );
    }

    private function power(TerrainType $homeland): PowerBowlsStateData
    {
        return match ($homeland) {
            TerrainType::Swamp => new PowerBowlsStateData(bowlOne: 3, bowlTwo: 9),
            TerrainType::Forest => new PowerBowlsStateData(bowlOne: 4, bowlTwo: 8),
            default => new PowerBowlsStateData(bowlOne: 5, bowlTwo: 7),
        };
    }

    private function knowledge(PlanningBundleData $bundle): KnowledgeStateData
    {
        $knowledge = new KnowledgeStateData();

        if ($bundle->homeland === TerrainType::Forest) {
            $knowledge->banking++;
            $knowledge->law++;
            $knowledge->engineering++;
            $knowledge->medicine++;
        }

        [$banking, $law, $engineering, $medicine, $unassignedSteps] = match ($bundle->faction) {
            Faction::Blessed => [1, 1, 1, 1, 0],
            Faction::Felines => [1, 0, 0, 1, 0],
            Faction::Goblins, Faction::Omar => [1, 0, 1, 0, 0],
            Faction::Illusionists => [0, 0, 0, 2, 0],
            Faction::Lizards => [0, 0, 0, 0, 2],
            Faction::Moles => [0, 0, 2, 0, 0],
            Faction::Monks => [0, 1, 0, 0, 0],
            Faction::Navigators => [0, 3, 0, 0, 0],
            Faction::Philosophers => [2, 0, 0, 0, 0],
            Faction::Psychics => [1, 0, 0, 1, 0],
            Faction::Inventors => [0, 0, 0, 0, 0],
        };

        $knowledge->banking += $banking;
        $knowledge->law += $law;
        $knowledge->engineering += $engineering;
        $knowledge->medicine += $medicine;
        $knowledge->unassignedSteps += $unassignedSteps;

        return $knowledge;
    }

    private function colorFor(TerrainType $terrain): PlayerColor
    {
        return match ($terrain) {
            TerrainType::Desert => PlayerColor::Yellow,
            TerrainType::Plains => PlayerColor::Brown,
            TerrainType::Swamp => PlayerColor::Black,
            TerrainType::Lake => PlayerColor::Blue,
            TerrainType::Forest => PlayerColor::Green,
            TerrainType::Mountain => PlayerColor::Grey,
            TerrainType::Wasteland => PlayerColor::Red,
        };
    }
}
