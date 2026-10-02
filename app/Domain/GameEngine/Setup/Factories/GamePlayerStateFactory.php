<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Setup\Factories;

use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\BookSupplyData;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\Research\Actions\AdvanceKnowledgeAction;
use App\Domain\GameEngine\Research\Data\KnowledgeStateData;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\Setup\Data\PlanningBundleData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Models\GamePlayer;

final class GamePlayerStateFactory
{
    public function __construct(private AdvanceKnowledgeAction $advanceKnowledge)
    {
    }

    public function create(
        GamePlayer $player,
        PlanningBundleData $bundle,
        ?GameStateData $state = null,
    ): GamePlayerStateData {
        return $this->createForPlayer($player->id, $player->user_id, $bundle, $state);
    }

    public function createForPlayer(
        int $playerId,
        ?int $userId,
        PlanningBundleData $bundle,
        ?GameStateData $state = null,
    ): GamePlayerStateData {
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

        $playerState = new GamePlayerStateData(
            playerId: $playerId,
            userId: $userId,
            color: $this->colorFor($bundle->homeland),
            faction: $bundle->faction,
            homeland: $bundle->homeland,
            roundBonus: $bundle->roundBonus,
            resources: $resources,
            knowledge: new KnowledgeStateData(unassignedSteps: $knowledge->unassignedSteps),
            shippingLevel: $bundle->homeland === TerrainType::Lake ? 1 : 0,
            unassignedSpades: $bundle->homeland === TerrainType::Desert ? 1 : 0,
        );

        $state ??= new GameStateData();

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $this->advanceKnowledge->execute(
                $state,
                $playerState,
                $discipline,
                $knowledge->{$discipline->value},
            );
        }

        return $playerState;
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
            TerrainType::Water => throw new \LogicException('Water cannot be a player homeland.'),
        };
    }
}
