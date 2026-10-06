<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Research;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BridgeStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Actions\ApplyInnovationRewardAction;
use App\Domain\GameEngine\Research\Data\KnowledgeStateData;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Towns\Enums\TownTile;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InnovationRewardsTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('steelBridgeCountProvider')]
    public function test_steel_scores_installed_bridges_without_requiring_owned_buildings(
        int $installedBridgeCount,
        int $expectedVictoryPoints,
    ): void {
        $player = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
        );
        $bridges = [new BridgeStateData('other:0', 'other:1', 16)];

        for ($index = 0; $index < $installedBridgeCount; $index++) {
            $bridges[] = new BridgeStateData($index.':0', $index.':1', 15);
        }

        $state = new GameStateData(
            board: new BoardStateData(bridges: $bridges),
            players: [$player],
        );

        $reward = app(ApplyInnovationRewardAction::class)->execute($state, $player, Innovation::Steel);

        $this->assertSame($expectedVictoryPoints, $reward['victoryPoints']);
        $this->assertSame(20 + $expectedVictoryPoints, $player->victoryPoints);
    }

    /** @return array<string, array{int, int}> */
    public static function steelBridgeCountProvider(): array
    {
        return [
            'three bridges remaining' => [0, 0],
            'two bridges remaining' => [1, 8],
            'one bridge remaining' => [2, 12],
            'no bridges remaining' => [3, 18],
        ];
    }

    public function test_palace_innovation_adds_two_new_power_tokens_directly_to_bowl_three(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(
                power: new PowerBowlsStateData(bowlOne: 3, bowlTwo: 4, bowlThree: 1),
            ),
        );
        $state = new GameStateData(players: [$playerState]);

        $reward = app(ApplyInnovationRewardAction::class)->execute(
            $state,
            $playerState,
            Innovation::Palace,
        );

        $this->assertSame(3, $playerState->resources->power->bowlOne);
        $this->assertSame(4, $playerState->resources->power->bowlTwo);
        $this->assertSame(3, $playerState->resources->power->bowlThree);
        $this->assertSame(2, $reward['power']);
    }

    #[DataProvider('immediateInnovationVictoryPointProvider')]
    public function test_immediate_innovations_grant_their_victory_points(
        Innovation $innovation,
        int $expectedVictoryPoints,
    ): void {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            knowledge: new KnowledgeStateData(banking: 4, law: 3, engineering: 2, medicine: 1),
            townTileIds: [TownTile::Tools->value, TownTile::Coins->value],
        );
        $buildingTypes = [
            BuildingType::Workshop,
            BuildingType::Workshop,
            BuildingType::Workshop,
            BuildingType::Workshop,
            BuildingType::Workshop,
            BuildingType::School,
            BuildingType::School,
            BuildingType::School,
            BuildingType::Guild,
            BuildingType::Guild,
            BuildingType::Guild,
        ];
        $hexes = array_map(
            static fn (BuildingType $type, int $index): BoardHexStateData => new BoardHexStateData(
                id: $index.':0',
                q: $index,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                building: new BuildingStateData($type, 15, isNeutral: true),
            ),
            $buildingTypes,
            array_keys($buildingTypes),
        );
        $state = new GameStateData(
            board: new BoardStateData(
                hexes: $hexes,
                bridges: [
                    new BridgeStateData('0:0', '1:0', 15),
                    new BridgeStateData('2:0', '3:0', 15),
                    new BridgeStateData('4:0', '5:0', 15),
                ],
            ),
            players: [$playerState],
        );

        $reward = app(ApplyInnovationRewardAction::class)->execute($state, $playerState, $innovation);

        $this->assertSame($expectedVictoryPoints, $reward['victoryPoints']);
        $this->assertSame(20 + $expectedVictoryPoints, $playerState->victoryPoints);

        if ($innovation === Innovation::Architecture) {
            $this->assertSame(3, $reward['knowledgeSteps']);
        }
    }

    /** @return array<string, array{Innovation, int}> */
    public static function immediateInnovationVictoryPointProvider(): array
    {
        return [
            'sewage system' => [Innovation::SewageSystem, 10],
            'architecture' => [Innovation::Architecture, 10],
            'library' => [Innovation::Library, 7],
            'league of cities' => [Innovation::LeagueOfCities, 10],
            'telecommunication' => [Innovation::Telecommunication, 18],
            'steel' => [Innovation::Steel, 18],
            'census' => [Innovation::Census, 18],
            'science' => [Innovation::Science, 15],
        ];
    }

    public function test_immediate_innovations_grant_books_knowledge_scholar_and_advancement(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
        );
        $state = new GameStateData(players: [$playerState]);

        $deusExMachinaReward = app(ApplyInnovationRewardAction::class)
            ->execute($state, $playerState, Innovation::DeusExMachina);
        $steamEngineReward = app(ApplyInnovationRewardAction::class)
            ->execute($state, $playerState, Innovation::SteamEngine);

        $this->assertSame(3, $playerState->resources->books->unassigned);
        $this->assertSame(0, $deusExMachinaReward['developmentTrackBooks']);
        $this->assertSame(2, $steamEngineReward['developmentTrackBooks']);
        $this->assertSame(1, $playerState->knowledge->banking);
        $this->assertSame(1, $playerState->knowledge->law);
        $this->assertSame(1, $playerState->knowledge->engineering);
        $this->assertSame(1, $playerState->knowledge->medicine);
        $this->assertSame(1, $playerState->resources->scholars);
        $this->assertSame(1, $playerState->shippingLevel);
        $this->assertSame(1, $playerState->terraformingLevel);
        $this->assertSame(22, $playerState->victoryPoints);
    }

    public function test_steam_engine_scores_each_development_track_step_for_the_round_goal(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
        );
        $state = new GameStateData(
            round: new RoundStateData(scoringTileId: RoundScoringTile::TrackEngineering->value),
            players: [$playerState],
        );

        $reward = app(ApplyInnovationRewardAction::class)->execute($state, $playerState, Innovation::SteamEngine);

        $this->assertSame(1, $playerState->shippingLevel);
        $this->assertSame(1, $playerState->terraformingLevel);
        $this->assertSame(28, $playerState->victoryPoints);
        $this->assertSame(8, $reward['victoryPoints']);
    }
}
