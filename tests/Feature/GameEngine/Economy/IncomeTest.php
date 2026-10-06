<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Economy;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Actions\ApplyIncomeAction;
use App\Domain\GameEngine\Economy\Data\IncomeReceiptData;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Economy\Services\PlayerIncomeCalculator;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Data\KnowledgeStateData;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Http\Resources\GameResource;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IncomeTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('forecastRoundBonusProvider')]
    public function test_income_forecast_includes_round_bonus_only_after_passing_and_choosing(
        bool $hasPassed,
        ?int $choosingPlayerId,
        int $expectedCoins,
    ): void {
        $player = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
        );
        $state = new GameStateData(
            players: [$player],
            passedPlayerIds: $hasPassed ? [15] : [],
            pendingInteraction: $choosingPlayerId === null ? null : new PendingInteractionData(
                PendingInteractionType::ChooseRoundBonus,
                $choosingPlayerId,
                [],
            ),
        );
        $game = Game::factory()->create(['state' => $state, 'phase' => GamePhase::Actions]);

        $data = (new GameResource($game))->resolve(Request::create('/'));

        $this->assertSame($expectedCoins, $data['playerBoardStates'][0]['income']['coins']);
        $this->assertSame(1, $data['playerBoardStates'][0]['income']['tools']);
    }

    /** @return array<string, array{bool, ?int, int}> */
    public static function forecastRoundBonusProvider(): array
    {
        return [
            'has not passed' => [false, null, 0],
            'choosing a replacement' => [true, 15, 0],
            'has chosen a replacement' => [true, null, 6],
            'another player is choosing' => [true, 16, 6],
        ];
    }

    public function test_player_income_is_calculated_from_buildings_and_owned_tiles(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Grey,
            faction: Faction::Omar,
            homeland: TerrainType::Mountain,
            roundBonus: RoundBonus::PowerCoins,
            palaceId: PalaceAbility::Palace08->value,
            competencyIds: [
                Competency::Competency01->value,
                Competency::Competency02->value,
                Competency::Competency03->value,
            ],
            inventionIds: [
                Innovation::Workshop->value,
                Innovation::Guild->value,
                Innovation::Palace->value,
            ],
        );
        $buildingTypes = [
            BuildingType::Workshop,
            BuildingType::Workshop,
            BuildingType::Guild,
            BuildingType::Guild,
            BuildingType::Guild,
            BuildingType::School,
            BuildingType::Tower,
        ];
        $board = new BoardStateData(
            hexes: array_map(
                static fn (BuildingType $buildingType, int $index): BoardHexStateData => new BoardHexStateData(
                    id: (string) $index,
                    q: $index,
                    r: 0,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    building: new BuildingStateData(
                        $buildingType,
                        15,
                        isNeutral: $buildingType === BuildingType::Tower,
                    ),
                ),
                $buildingTypes,
                array_keys($buildingTypes),
            ),
        );

        $income = PlayerIncomeCalculator::calculate($playerState, $board);

        $this->assertInstanceOf(IncomeReceiptData::class, $income);
        $this->assertSame(15, $income->playerId);
        $this->assertSame([
            'tools' => 8,
            'coins' => 22,
            'scholars' => 1,
            'power' => 17,
            'books' => 1,
            'knowledgeSteps' => 1,
            'victoryPoints' => 3,
        ], $income->resourceAmounts());

        $forecast = PlayerIncomeCalculator::calculate($playerState, $board, includeRoundBonus: false);

        $this->assertSame([
            'tools' => 8,
            'coins' => 20,
            'scholars' => 1,
            'power' => 13,
            'books' => 1,
            'knowledgeSteps' => 1,
            'victoryPoints' => 3,
        ], $forecast->resourceAmounts());
        $this->assertSame(RoundBonus::PowerCoins, $playerState->roundBonus);
    }

    #[DataProvider('guildPowerIncomeProvider')]
    public function test_guilds_grant_power_income_by_their_position(int $guildCount, int $expectedPower): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
        );
        $board = new BoardStateData(hexes: array_map(
            static fn (int $index): BoardHexStateData => new BoardHexStateData(
                id: $index.':0',
                q: $index,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                building: new BuildingStateData(BuildingType::Guild, 15),
            ),
            range(1, $guildCount),
        ));

        $this->assertSame($expectedPower, PlayerIncomeCalculator::calculate($playerState, $board)->power);
    }

    /** @return array<string, array{int, int}> */
    public static function guildPowerIncomeProvider(): array
    {
        return [
            'first guild' => [1, 1],
            'second guild' => [2, 2],
            'third guild' => [3, 4],
            'fourth guild' => [4, 6],
        ];
    }

    public function test_grey_player_first_guild_grants_one_additional_coin(): void
    {
        $board = new BoardStateData(hexes: [new BoardHexStateData(
            id: '0:0',
            q: 0,
            r: 0,
            initialTerrain: TerrainType::Mountain,
            terrain: TerrainType::Mountain,
            building: new BuildingStateData(BuildingType::Guild, 15),
        )]);
        $greyPlayer = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Grey,
            faction: Faction::Omar,
            homeland: TerrainType::Mountain,
            roundBonus: RoundBonus::RiverWorkshop,
        );
        $greenPlayer = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::RiverWorkshop,
        );

        $this->assertSame(
            3,
            PlayerIncomeCalculator::calculate($greyPlayer, $board)->coins
                - PlayerIncomeCalculator::calculate($greyPlayer, new BoardStateData())->coins,
        );
        $this->assertSame(
            2,
            PlayerIncomeCalculator::calculate($greenPlayer, $board)->coins
                - PlayerIncomeCalculator::calculate($greenPlayer, new BoardStateData())->coins,
        );
    }

    public function test_grey_player_has_two_coin_default_income(): void
    {
        $greyPlayer = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Grey,
            faction: Faction::Blessed,
            homeland: TerrainType::Mountain,
            roundBonus: RoundBonus::RiverWorkshop,
        );
        $greenPlayer = new GamePlayerStateData(
            playerId: 16,
            userId: 26,
            color: PlayerColor::Green,
            faction: Faction::Omar,
            homeland: TerrainType::Mountain,
            roundBonus: RoundBonus::RiverWorkshop,
        );

        $this->assertSame(2, PlayerIncomeCalculator::calculate($greyPlayer, new BoardStateData())->coins);
        $this->assertSame(0, PlayerIncomeCalculator::calculate($greyPlayer, new BoardStateData())->power);
        $this->assertSame(2, PlayerIncomeCalculator::calculate($greenPlayer, new BoardStateData())->coins);
        $this->assertSame(2, PlayerIncomeCalculator::calculate($greenPlayer, new BoardStateData())->power);
    }

    public function test_tenth_competency_has_two_coin_and_two_power_income_without_a_tower(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::RiverWorkshop,
            competencyIds: [Competency::Competency10->value],
        );

        $income = PlayerIncomeCalculator::calculate($playerState, new BoardStateData());

        $this->assertSame(2, $income->coins);
        $this->assertSame(2, $income->power);
    }

    public function test_tower_does_not_have_its_own_income(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::RiverWorkshop,
        );
        $board = new BoardStateData(hexes: [new BoardHexStateData(
            id: '0:0',
            q: 0,
            r: 0,
            initialTerrain: TerrainType::Forest,
            terrain: TerrainType::Forest,
            building: new BuildingStateData(BuildingType::Tower, 15),
        )]);

        $income = PlayerIncomeCalculator::calculate($playerState, $board);

        $this->assertSame(0, $income->coins);
        $this->assertSame(0, $income->power);
    }

    #[DataProvider('workshopToolIncomeProvider')]
    public function test_only_the_fifth_workshop_does_not_grant_tool_income(
        int $workshopCount,
        int $expectedTools,
    ): void {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
        );
        $board = new BoardStateData(hexes: array_map(
            static fn (int $index): BoardHexStateData => new BoardHexStateData(
                id: $index.':0',
                q: $index,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                building: new BuildingStateData(BuildingType::Workshop, 15),
            ),
            range(1, $workshopCount),
        ));

        $this->assertSame($expectedTools, PlayerIncomeCalculator::calculate($playerState, $board)->tools);
    }

    /** @return array<string, array{int, int}> */
    public static function workshopToolIncomeProvider(): array
    {
        return [
            'two workshops' => [2, 3],
            'four workshops' => [4, 5],
            'five workshops' => [5, 5],
            'six workshops' => [6, 6],
        ];
    }

    public function test_income_is_applied_to_resources_power_books_and_knowledge(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Bridge,
            resources: new PlayerResourcesData(
                power: new PowerBowlsStateData(bowlOne: 1, bowlTwo: 2),
            ),
            palaceId: PalaceAbility::Palace06->value,
            competencyIds: [Competency::Competency01->value],
        );
        $state = new GameStateData(
            board: new BoardStateData(),
            players: [$playerState],
        );

        app(ApplyIncomeAction::class)->execute($state, $playerState);

        $this->assertSame(2, $playerState->resources->tools);
        $this->assertSame(2, $playerState->resources->books->unassigned);
        $this->assertSame(1, $playerState->knowledge->unassignedSteps);
        $this->assertSame(0, $playerState->resources->power->bowlOne);
        $this->assertSame(2, $playerState->resources->power->bowlTwo);
        $this->assertSame(1, $playerState->resources->power->bowlThree);
    }

    public function test_university_innovation_grants_two_victory_points_during_income(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            inventionIds: [Innovation::University->value],
        );

        app(ApplyIncomeAction::class)->execute(new GameStateData(players: [$playerState]), $playerState);

        $this->assertSame(22, $playerState->victoryPoints);
    }

    #[DataProvider('neutralBuildingInnovationIncomeProvider')]
    public function test_neutral_building_innovation_income_does_not_depend_on_building_placement(
        Innovation $innovation,
        BuildingType $buildingType,
        string $resource,
        int $expectedIncome,
    ): void {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::RiverWorkshop,
            inventionIds: [$innovation->value],
        );
        $boardWithNeutralBuilding = new BoardStateData(hexes: [
            new BoardHexStateData(
                id: '0:0',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                building: new BuildingStateData($buildingType, $playerState->playerId, isNeutral: true),
            ),
        ]);

        $this->assertSame(
            $expectedIncome,
            PlayerIncomeCalculator::calculate($playerState, new BoardStateData())->{$resource},
        );
        $this->assertSame(
            $expectedIncome,
            PlayerIncomeCalculator::calculate($playerState, $boardWithNeutralBuilding)->{$resource},
        );
    }

    /** @return array<string, array{Innovation, BuildingType, string, int}> */
    public static function neutralBuildingInnovationIncomeProvider(): array
    {
        return [
            'workshop gives three tools plus base income' => [
                Innovation::Workshop,
                BuildingType::Workshop,
                'tools',
                4,
            ],
            'guild gives five coins' => [Innovation::Guild, BuildingType::Guild, 'coins', 5],
            'university gives two victory points' => [
                Innovation::University,
                BuildingType::University,
                'victoryPoints',
                2,
            ],
            'palace gives four power' => [Innovation::Palace, BuildingType::Palace, 'power', 4],
        ];
    }

    public function test_second_competency_grants_three_victory_points_and_two_coins_during_income(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::RiverWorkshop,
            competencyIds: [Competency::Competency02->value],
        );

        app(ApplyIncomeAction::class)->execute(new GameStateData(players: [$playerState]), $playerState);

        $this->assertSame(23, $playerState->victoryPoints);
        $this->assertSame(2, $playerState->resources->coins);
    }

    public function test_university_grants_a_scholar_during_income_except_when_it_is_neutral(): void
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
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::University, 15),
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::University, 15, isNeutral: true),
                ),
            ]),
            players: [$playerState],
        );

        app(ApplyIncomeAction::class)->execute($state, $playerState);

        $this->assertSame(1, $playerState->resources->scholars);
    }

    public function test_knowledge_levels_from_nine_grant_discipline_income(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            knowledge: new KnowledgeStateData(banking: 9, law: 9, engineering: 9, medicine: 9),
        );

        $income = PlayerIncomeCalculator::calculate($playerState, new BoardStateData());

        $this->assertSame(9, $income->coins);
        $this->assertSame(6, $income->power);
        $this->assertSame(2, $income->tools);
        $this->assertSame(3, $income->victoryPoints);
    }
}
