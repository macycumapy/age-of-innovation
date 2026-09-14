<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Actions\ApplyFinalScoringAction;
use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BoardStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\KnowledgeStateData;
use App\Domain\Game\Data\NeutralKnowledgeStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Enums\TwoPlayerTerritoryScore;
use App\Domain\Game\Factories\GameSetupPoolFactory;
use PHPUnit\Framework\TestCase;

class ApplyFinalScoringActionTest extends TestCase
{
    public function test_it_awards_network_and_each_knowledge_discipline_with_ties(): void
    {
        $state = new GameStateData(
            board: new BoardStateData(hexes: [
                $this->buildingHex('a', 1, ['b']),
                $this->buildingHex('b', 1, ['a']),
                $this->buildingHex('c', 2, ['d']),
                $this->buildingHex('d', 2, ['c']),
                $this->buildingHex('e', 3),
            ]),
            players: [
                $this->player(1, new KnowledgeStateData(banking: 5, law: 2, engineering: 5, medicine: 5)),
                $this->player(2, new KnowledgeStateData(banking: 5, law: 2, engineering: 4, medicine: 4)),
                $this->player(3, new KnowledgeStateData(banking: 3, law: 2, engineering: 4, medicine: 0)),
            ],
        );

        $scoring = (new ApplyFinalScoringAction())->execute($state);

        $this->assertSame([61, 52, 35], array_column($state->players, 'victoryPoints'));
        $this->assertSame([41, 32, 15], array_column($scoring, 'victoryPoints'));
        $this->assertSame([
            'source' => 'network',
            'id' => 'largest_network',
            'value' => 2,
            'rank' => 1,
            'points' => 15,
        ], $scoring[0]['sources'][0]);
        $this->assertSame([
            'source' => 'network',
            'id' => 'largest_network',
            'value' => 1,
            'rank' => 3,
            'points' => 6,
        ], $scoring[2]['sources'][0]);
        $this->assertNotContains('medicine', array_column($scoring[2]['sources'], 'id'));
    }

    public function test_neutral_faction_occupies_knowledge_places_without_receiving_points(): void
    {
        $state = new GameStateData(
            players: [
                $this->player(1, new KnowledgeStateData(banking: 8)),
                $this->player(2, new KnowledgeStateData(banking: 4)),
            ],
            neutralKnowledge: new NeutralKnowledgeStateData(
                PlayerColor::Black,
                new KnowledgeStateData(banking: 6),
                ['banking', 'law', 'engineering', 'medicine'],
            ),
        );

        $scoring = (new ApplyFinalScoringAction())->execute($state);

        $this->assertSame([43, 37], array_column($state->players, 'victoryPoints'));
        $this->assertSame([23, 17], array_column($scoring, 'victoryPoints'));
        $this->assertSame(1, $scoring[0]['sources'][1]['rank']);
        $this->assertSame(3, $scoring[1]['sources'][1]['rank']);
    }

    public function test_neutral_faction_territory_occupies_a_network_place_without_receiving_points(): void
    {
        $setupPool = (new GameSetupPoolFactory())->createFromSeed(2, 'territory-scoring-test');
        $setupPool->twoPlayerTerritoryScore = TwoPlayerTerritoryScore::Twelve;
        $state = new GameStateData(
            board: new BoardStateData(hexes: [
                $this->buildingHex('a', 1, ['b']),
                $this->buildingHex('b', 1, ['a']),
                $this->buildingHex('c', 2),
            ]),
            players: [
                $this->player(1, new KnowledgeStateData()),
                $this->player(2, new KnowledgeStateData()),
            ],
            setupPool: $setupPool,
        );

        $scoring = (new ApplyFinalScoringAction())->execute($state);

        $this->assertSame([32, 26], array_column($state->players, 'victoryPoints'));
        $this->assertSame([12, 6], array_column($scoring, 'victoryPoints'));
        $this->assertSame(2, $scoring[0]['sources'][0]['rank']);
        $this->assertSame(3, $scoring[1]['sources'][0]['rank']);
    }

    /** @param list<string> $adjacentHexIds */
    private function buildingHex(string $id, int $playerId, array $adjacentHexIds = []): BoardHexStateData
    {
        return new BoardHexStateData(
            id: $id,
            q: 0,
            r: 0,
            initialTerrain: TerrainType::Forest,
            terrain: TerrainType::Forest,
            adjacentHexIds: $adjacentHexIds,
            building: new BuildingStateData(BuildingType::Workshop, $playerId),
        );
    }

    private function player(int $playerId, KnowledgeStateData $knowledge): GamePlayerStateData
    {
        return new GamePlayerStateData(
            playerId: $playerId,
            userId: $playerId,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            knowledge: $knowledge,
        );
    }
}
