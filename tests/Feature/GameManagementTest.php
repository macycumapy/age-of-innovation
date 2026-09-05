<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Game\Actions\AdvanceDevelopmentTrackAction;
use App\Domain\Game\Actions\AdvanceKnowledgeAction;
use App\Domain\Game\Actions\ApplyIncomeAction;
use App\Domain\Game\Actions\ApplyInnovationRewardAction;
use App\Domain\Game\Actions\ApplyResourceExchangeAction;
use App\Domain\Game\Actions\CreateBuildingFollowUpInteractionAction;
use App\Domain\Game\Actions\CreatePowerOffersAfterBuildingAction;
use App\Domain\Game\Actions\DetermineStartingBuildingOrderAction;
use App\Domain\Game\Actions\FindEligibleTerraformHexesAction;
use App\Domain\Game\Actions\FindEligibleTownHexesAction;
use App\Domain\Game\Actions\ResolveCompletedStartingSetupAction;
use App\Domain\Game\Actions\ResolveIncomePhaseAction;
use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BoardStateData;
use App\Domain\Game\Data\BookSupplyData;
use App\Domain\Game\Data\BridgeStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\KnowledgeStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\PlanningBundleData;
use App\Domain\Game\Data\PlayerPlanningSelectionData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\PowerBowlsStateData;
use App\Domain\Game\Data\RoundBonusOfferData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\BookAction;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\FinalRoundScoringTile;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\Innovation;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\MapVariant;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\PowerAction;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\RoundScoringTile;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Enums\TownTile;
use App\Domain\Game\Factories\BoardStateFactory;
use App\Domain\Game\Factories\GamePlayerStateFactory;
use App\Domain\Game\Factories\GameSetupPoolFactory;
use App\Domain\Game\Services\PlayerIncomeCalculator;
use App\Models\Builders\GameBuilder;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GameManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_game_uses_custom_builder(): void
    {
        $this->assertInstanceOf(GameBuilder::class, Game::query());
    }

    public function test_terraforming_reaches_adjacent_hexes_and_hexes_within_shipping_range(): void
    {
        $player = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Grey,
            faction: Faction::Omar,
            homeland: TerrainType::Mountain,
            roundBonus: RoundBonus::PowerCoins,
        );
        $state = new GameStateData(board: new BoardStateData(hexes: [
            new BoardHexStateData(
                id: '0:0',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Mountain,
                terrain: TerrainType::Mountain,
                building: new BuildingStateData(BuildingType::Workshop, $player->playerId),
                adjacentHexIds: ['1:0', '0:1'],
            ),
            new BoardHexStateData(
                id: '1:0',
                q: 1,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
            ),
            new BoardHexStateData(
                id: '0:1',
                q: 0,
                r: 1,
                initialTerrain: TerrainType::Water,
                terrain: TerrainType::Water,
                adjacentHexIds: ['0:0', '0:2', '0:3'],
            ),
            new BoardHexStateData(
                id: '0:2',
                q: 0,
                r: 2,
                initialTerrain: TerrainType::Desert,
                terrain: TerrainType::Desert,
                adjacentHexIds: ['0:1'],
            ),
            new BoardHexStateData(
                id: '0:3',
                q: 0,
                r: 3,
                initialTerrain: TerrainType::Water,
                terrain: TerrainType::Water,
                adjacentHexIds: ['0:1', '0:4'],
            ),
            new BoardHexStateData(
                id: '0:4',
                q: 0,
                r: 4,
                initialTerrain: TerrainType::Plains,
                terrain: TerrainType::Plains,
                adjacentHexIds: ['0:3'],
            ),
        ]));
        $findEligibleHexes = app(FindEligibleTerraformHexesAction::class);

        $this->assertSame(
            ['1:0'],
            $findEligibleHexes->execute($state, $player, TerrainType::Mountain),
        );

        $state->board->bridges[] = new BridgeStateData('0:0', '0:2', $player->playerId);

        $this->assertSame(
            ['1:0', '0:2'],
            $findEligibleHexes->execute($state, $player, TerrainType::Mountain),
        );

        $player->shippingLevel = 1;

        $this->assertSame(
            ['1:0', '0:2'],
            $findEligibleHexes->execute($state, $player, TerrainType::Mountain),
        );

        $player->roundBonus = RoundBonus::RiverWorkshop;

        $this->assertSame(
            ['1:0', '0:2', '0:4'],
            $findEligibleHexes->execute($state, $player, TerrainType::Mountain),
        );
    }

    public function test_power_offer_sums_all_adjacent_buildings_with_annexes_before_limiting_received_power(): void
    {
        $builder = new GamePlayerStateData(
            playerId: 1,
            userId: 11,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
        );
        $neighbor = new GamePlayerStateData(
            playerId: 2,
            userId: 22,
            color: PlayerColor::Blue,
            faction: Faction::Blessed,
            homeland: TerrainType::Mountain,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(
                power: new PowerBowlsStateData(bowlOne: 4),
            ),
        );
        $state = new GameStateData(
            turnOrder: [1, 2],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '8:4',
                    q: 8,
                    r: 4,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['9:4', '8:5'],
                    building: new BuildingStateData(BuildingType::School, 1),
                ),
                new BoardHexStateData(
                    id: '9:4',
                    q: 9,
                    r: 4,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    building: new BuildingStateData(BuildingType::University, 2, hasAnnex: true),
                ),
                new BoardHexStateData(
                    id: '8:5',
                    q: 8,
                    r: 5,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    building: new BuildingStateData(BuildingType::School, 2, hasAnnex: true),
                ),
            ]),
            players: [$builder, $neighbor],
        );

        $nextActiveUserId = app(CreatePowerOffersAfterBuildingAction::class)->execute($state, 1, '8:4');

        $this->assertSame(22, $nextActiveUserId);
        $this->assertSame(PendingInteractionType::PowerOffer, $state->pendingInteraction?->type);
        $this->assertSame(7, $state->pendingInteraction?->context['powerAmount']);
    }

    #[DataProvider('terraformingToolCosts')]
    public function test_player_can_buy_spades_for_tools_at_the_current_terraforming_cost(
        int $terraformingLevel,
        int $toolCost,
    ): void {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
        ]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    building: new BuildingStateData(BuildingType::Workshop, $player->id),
                    adjacentHexIds: ['1:0'],
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: TerrainType::Lake,
                    terrain: TerrainType::Lake,
                    adjacentHexIds: ['0:0'],
                ),
            ]),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Grey,
                faction: Faction::Omar,
                homeland: TerrainType::Mountain,
                roundBonus: RoundBonus::PowerCoins,
                resources: new PlayerResourcesData(tools: 6),
                terraformingLevel: $terraformingLevel,
            )],
        )]);

        $state = $game->state;
        $state->players[0]->resources->tools = $toolCost * 2 - 1;
        $game->update(['state' => $state]);

        $this->actingAs($user)->post(route('games.paid-terraforming', $game), [
            'hex_id' => '1:0',
        ])->assertSessionHasErrors('hex_id');
        $game->refresh();

        $this->assertSame($toolCost * 2 - 1, $game->state->players[0]->resources->tools);
        $this->assertNull($game->state->pendingInteraction);

        $state = $game->state;
        $state->players[0]->resources->tools = 6;
        $game->update(['state' => $state]);

        $this->post(route('games.paid-terraforming', $game), [
            'hex_id' => '1:0',
        ])->assertRedirect(route('games.show', $game));
        $game->refresh();

        $this->assertSame(6 - $toolCost * 2, $game->state->players[0]->resources->tools);
        $this->assertSame(2, $game->state->players[0]->unassignedSpades);
        $this->assertSame(PendingInteractionType::SpendSpades, $game->state->pendingInteraction?->type);
        $this->assertSame($toolCost * 2, $game->state->pendingInteraction?->context['paidTools']);
        $this->assertSame('1:0', $game->state->pendingInteraction?->context['selectedHexId']);
        $this->assertSame(TerrainType::Mountain, $game->state->board->hexes[1]->terrain);

        $this->post(route('games.current-turn.restart', $game));
        $game->refresh();

        $this->assertSame(6, $game->state->players[0]->resources->tools);
        $this->assertSame(0, $game->state->players[0]->unassignedSpades);
        $this->assertNull($game->state->pendingInteraction);

        $this->post(route('games.paid-terraforming', $game), ['hex_id' => '1:0']);
        $this->delete(route('games.starting-spade.destroy', $game));
        $game->refresh();

        $this->assertSame(TerrainType::Lake, $game->state->board->hexes[1]->terrain);

        $this->post(route('games.paid-terraforming', $game), ['hex_id' => '1:0']);
        $this->post(route('games.starting-spade.finish', $game));
        $game->refresh();

        $paidTerraformingAction = $game->actions()->oldest('sequence')->firstOrFail();

        $this->assertSame(6 - $toolCost * 2, $game->state->players[0]->resources->tools);
        $this->assertSame(0, $game->state->players[0]->unassignedSpades);
        $this->assertSame($toolCost * 2, $paidTerraformingAction->payload['paid_tools']);
        $this->assertSame(2, $paidTerraformingAction->payload['paid_spade_count']);
        $this->assertSame(2, $paidTerraformingAction->payload['spades_spent']);
    }

    /** @return array<string, array{int, int}> */
    public static function terraformingToolCosts(): array
    {
        return [
            'no upgrades' => [0, 3],
            'one upgrade' => [1, 2],
            'two upgrades' => [2, 1],
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

        $this->assertEquals([
            'tools' => 8,
            'coins' => 21,
            'scholars' => 1,
            'power' => 17,
            'books' => 1,
            'knowledgeSteps' => 1,
            'victoryPoints' => 0,
        ], PlayerIncomeCalculator::calculate($playerState, $board));
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

        $this->assertSame($expectedPower, PlayerIncomeCalculator::calculate($playerState, $board)['power']);
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

        $this->assertSame($expectedTools, PlayerIncomeCalculator::calculate($playerState, $board)['tools']);
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

    public function test_knowledge_level_eight_requires_and_spends_a_town_key(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            knowledge: new KnowledgeStateData(banking: 7),
        );
        $state = new GameStateData(players: [$playerState]);
        $advanceKnowledge = app(AdvanceKnowledgeAction::class);

        $advanceKnowledge->execute($state, $playerState, KnowledgeDiscipline::Banking, 2);
        $this->assertSame(7, $playerState->knowledge->banking);

        $playerState->townTileIds[] = TownTile::Tools->value;
        $advanceKnowledge->execute($state, $playerState, KnowledgeDiscipline::Banking, 2);

        $this->assertSame(9, $playerState->knowledge->banking);
        $this->assertSame([KnowledgeDiscipline::Banking], $playerState->knowledge->unlockedDisciplines);
    }

    public function test_only_one_player_may_reach_the_top_of_each_knowledge_discipline(): void
    {
        $leader = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            knowledge: new KnowledgeStateData(banking: 12, unlockedDisciplines: [KnowledgeDiscipline::Banking]),
            townTileIds: [TownTile::Tools->value],
        );
        $challenger = new GamePlayerStateData(
            playerId: 16,
            userId: 26,
            color: PlayerColor::Red,
            faction: Faction::Blessed,
            homeland: TerrainType::Desert,
            roundBonus: RoundBonus::Coins,
            knowledge: new KnowledgeStateData(banking: 11, unlockedDisciplines: [KnowledgeDiscipline::Banking]),
            townTileIds: [TownTile::Coins->value],
        );
        $state = new GameStateData(players: [$leader, $challenger]);

        app(AdvanceKnowledgeAction::class)->execute(
            $state,
            $challenger,
            KnowledgeDiscipline::Banking,
            1,
        );

        $this->assertSame(11, $challenger->knowledge->banking);
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

        $this->assertSame(9, $income['coins']);
        $this->assertSame(6, $income['power']);
        $this->assertSame(2, $income['tools']);
        $this->assertSame(3, $income['victoryPoints']);
    }

    public function test_income_skips_players_without_choices_and_stops_on_a_required_choice(): void
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create();
        $firstPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[0]->id,
            'seat' => 1,
        ]);
        $secondPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[1]->id,
            'seat' => 2,
        ]);
        $firstPlayerState = new GamePlayerStateData(
            $firstPlayer->id,
            $users[0]->id,
            PlayerColor::Green,
            Faction::Blessed,
            TerrainType::Forest,
            RoundBonus::Coins,
        );
        $secondPlayerState = new GamePlayerStateData(
            $secondPlayer->id,
            $users[1]->id,
            PlayerColor::Grey,
            Faction::Felines,
            TerrainType::Mountain,
            RoundBonus::Bridge,
        );
        $state = new GameStateData(
            turnOrder: [$firstPlayer->id, $secondPlayer->id],
            board: new BoardStateData(),
            players: [$firstPlayerState, $secondPlayerState],
        );
        $players = $game->players()->get();

        [$activePlayer, $phase] = app(ResolveIncomePhaseAction::class)->execute($state, $players);

        $this->assertSame($secondPlayer->id, $activePlayer->id);
        $this->assertSame(GamePhase::Income, $phase);
        $this->assertSame(GamePhase::Income, $state->round->phase);
        $this->assertSame(1, $state->round->incomeTurnIndex);
        $this->assertSame([$secondPlayer->id], $state->round->incomeOrder);
        $this->assertSame(PendingInteractionType::ChooseStartingResources, $state->pendingInteraction?->type);
        $this->assertSame($secondPlayer->id, $state->pendingInteraction?->playerId);
        $this->assertSame(1, $state->pendingInteraction?->context['bookCount']);
        $this->assertSame([], $state->round->incomeReceipts);
        $this->assertSame(0, $firstPlayerState->resources->tools);
        $this->assertSame(0, $firstPlayerState->resources->coins);
        $this->assertSame(1, $secondPlayerState->resources->books->unassigned);

        $secondPlayerState->resources->books->unassigned = 0;
        $secondPlayerTools = $secondPlayerState->resources->tools;
        [$activePlayer, $phase] = app(ResolveIncomePhaseAction::class)->execute($state, $players);

        $this->assertSame($firstPlayer->id, $activePlayer->id);
        $this->assertSame(GamePhase::Actions, $phase);
        $this->assertSame(GamePhase::Actions, $state->round->phase);
        $this->assertSame(1, $firstPlayerState->resources->tools);
        $this->assertSame(6, $firstPlayerState->resources->coins);
        $this->assertSame($secondPlayerTools + 1, $secondPlayerState->resources->tools);
        $this->assertSame([], $state->round->incomeOrder);
        $this->assertSame([], $state->round->incomeReceipts);
    }

    public function test_income_continues_automatically_after_required_resources_are_distributed(): void
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Income,
            'active_player_id' => $users[0]->id,
        ]);
        $firstPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[0]->id,
            'seat' => 1,
        ]);
        $secondPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[1]->id,
            'seat' => 2,
        ]);
        $firstPlayerState = new GamePlayerStateData(
            $firstPlayer->id,
            $users[0]->id,
            PlayerColor::Green,
            Faction::Blessed,
            TerrainType::Forest,
            RoundBonus::Coins,
        );
        $firstPlayerState->resources->books->unassigned = 1;
        $secondPlayerState = new GamePlayerStateData(
            $secondPlayer->id,
            $users[1]->id,
            PlayerColor::Grey,
            Faction::Felines,
            TerrainType::Mountain,
            RoundBonus::Coins,
        );
        $state = new GameStateData(
            turnOrder: [$firstPlayer->id, $secondPlayer->id],
            board: new BoardStateData(),
            players: [$firstPlayerState, $secondPlayerState],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseStartingResources,
                $firstPlayer->id,
                array_column(KnowledgeDiscipline::cases(), 'value'),
                [
                    'bookCount' => 1,
                    'knowledgeStepCount' => 0,
                    'competencyIds' => [],
                    'phase' => GamePhase::Income->value,
                ],
            ),
        );
        $state->round->phase = GamePhase::Income;
        $state->round->incomeTurnIndex = 1;
        $state->round->incomeOrder = [$firstPlayer->id];
        $state->round->incomeReceipts = [];
        $game->update(['state' => $state]);

        $this->actingAs($users[0])->post(route('games.starting-resources.store', $game), [
            'book_counts' => [
                'banking' => 1,
                'law' => 0,
                'engineering' => 0,
                'medicine' => 0,
            ],
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $updatedFirstPlayer = collect($game->state->players)->firstWhere('playerId', $firstPlayer->id);
        $updatedSecondPlayer = collect($game->state->players)->firstWhere('playerId', $secondPlayer->id);

        $this->assertSame(GamePhase::Actions, $game->phase);
        $this->assertSame(GamePhase::Actions, $game->state->round->phase);
        $this->assertSame($users[0]->id, $game->active_player_id);
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(0, $updatedFirstPlayer?->resources->books->unassigned);
        $this->assertSame(1, $updatedFirstPlayer?->resources->books->banking);
        $this->assertSame(1, $updatedSecondPlayer?->resources->tools);
        $this->assertSame(8, $updatedSecondPlayer?->resources->coins);
        $this->assertSame(
            GameActionType::ChooseIncomeResources,
            $game->actions()->where('type', '!=', GameActionType::PhaseCheckpoint)->latest('sequence')->firstOrFail()->type,
        );
        $this->assertEquals([
            [
                'player_id' => $firstPlayer->id,
                'tools' => 1,
                'coins' => 6,
                'scholars' => 0,
                'power' => 0,
                'books' => 0,
                'knowledge_steps' => 0,
                'victory_points' => 0,
            ],
            [
                'player_id' => $secondPlayer->id,
                'tools' => 1,
                'coins' => 8,
                'scholars' => 0,
                'power' => 0,
                'books' => 0,
                'knowledge_steps' => 0,
                'victory_points' => 0,
            ],
        ], $game->actions()->where('type', '!=', GameActionType::PhaseCheckpoint)->latest('sequence')->firstOrFail()->payload['income_receipts']);
    }

    public function test_manual_knowledge_step_reaching_level_nine_is_applied_before_other_income(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Income,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $playerState = new GamePlayerStateData(
            playerId: $player->id,
            userId: $user->id,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            knowledge: new KnowledgeStateData(banking: 8),
            competencyIds: [Competency::Competency01->value],
        );
        $state = new GameStateData(
            turnOrder: [$player->id],
            players: [$playerState],
        );
        $state->round->phase = GamePhase::Income;

        [$activePlayer, $phase] = app(ResolveIncomePhaseAction::class)->execute(
            $state,
            $game->players()->get(),
        );
        $game->update([
            'active_player_id' => $activePlayer->user_id,
            'phase' => $phase,
            'state' => $state,
        ]);

        $this->assertSame(0, $playerState->resources->coins);
        $this->assertSame(1, $playerState->knowledge->unassignedSteps);

        $this->actingAs($user)->post(route('games.starting-resources.store', $game), [
            'knowledge_counts' => [
                'banking' => 1,
                'law' => 0,
                'engineering' => 0,
                'medicine' => 0,
            ],
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(GamePhase::Actions, $game->phase);
        $this->assertSame(9, $game->state->players[0]->knowledge->banking);
        $this->assertSame(9, $game->state->players[0]->resources->coins);
        $this->assertSame(2, $game->state->players[0]->resources->tools);
    }

    public function test_active_player_can_sacrifice_power_without_ending_the_turn(): void
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $users[0]->id,
            'version' => 7,
        ]);
        $activePlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[0]->id,
            'seat' => 1,
        ]);
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[1]->id,
            'seat' => 2,
        ]);
        $game->update([
            'state' => new GameStateData(
                turnOrder: [$activePlayer->id],
                round: new RoundStateData(phase: GamePhase::Actions),
                players: [
                    new GamePlayerStateData(
                        playerId: $activePlayer->id,
                        userId: $users[0]->id,
                        color: PlayerColor::Green,
                        faction: Faction::Blessed,
                        homeland: TerrainType::Forest,
                        roundBonus: RoundBonus::Coins,
                        resources: new PlayerResourcesData(
                            power: new PowerBowlsStateData(bowlTwo: 5, bowlThree: 1),
                        ),
                    ),
                ],
            ),
        ]);

        $this->actingAs($users[1])
            ->post(route('games.power-sacrifice.store', $game), ['amount' => 1])
            ->assertForbidden();

        $this->actingAs($users[0])
            ->post(route('games.power-sacrifice.store', $game), ['amount' => 3])
            ->assertSessionHasErrors('amount');

        $game->refresh();
        $this->assertSame(5, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame(1, $game->state->players[0]->resources->power->bowlThree);
        $this->assertSame(0, $game->actions()->count());

        $this->post(route('games.power-sacrifice.store', $game), ['amount' => 2])
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame(3, $game->state->players[0]->resources->power->bowlThree);
        $this->assertSame($users[0]->id, $game->active_player_id);
        $this->assertSame(GamePhase::Actions, $game->phase);
        $this->assertSame(8, $game->version);

        $action = $game->actions()->sole();
        $this->assertSame(GameActionType::SacrificePower, $action->type);
        $this->assertSame(['amount' => 2], $action->payload);
        $this->assertSame('power_sacrificed', $action->events[0]['type']);
        $this->assertSame(2, $action->events[0]['sacrificed']);
        $this->assertSame(2, $action->events[0]['moved_to_bowl_three']);
    }

    public function test_power_action_can_sacrifice_missing_power_and_apply_the_effect_atomically(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $game->update([
            'state' => new GameStateData(
                turnOrder: [$player->id],
                round: new RoundStateData(phase: GamePhase::Actions),
                players: [new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlTwo: 4, bowlThree: 2),
                    ),
                )],
            ),
        ]);

        $this->actingAs($user)
            ->post(route('games.power-action', $game), [
                'action' => PowerAction::GainTools->value,
                'sacrifice_amount' => 2,
            ])
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $playerState = $game->state->players[0];
        $this->assertSame(0, $playerState->resources->power->bowlTwo);
        $this->assertSame(0, $playerState->resources->power->bowlThree);
        $this->assertSame(4, $playerState->resources->power->bowlOne);
        $this->assertSame(2, $playerState->resources->tools);
        $this->assertContains(PowerAction::GainTools->value, $game->state->round->usedSharedActionIds);
        $this->assertSame(GameActionType::PowerAction, $game->actions()->sole()->type);
        $this->assertSame(2, $game->actions()->sole()->payload['sacrifice_amount']);

        $this->post(route('games.power-action', $game), [
            'action' => PowerAction::GainTools->value,
            'sacrifice_amount' => 0,
        ])->assertSessionHasErrors('action');

        $this->assertSame(1, $game->actions()->count());
    }

    public function test_player_can_activate_round_bonus_action_only_once_and_restart_the_turn(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $game->update([
            'state' => new GameStateData(
                turnOrder: [$player->id],
                round: new RoundStateData(phase: GamePhase::Actions),
                players: [new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Knowledge,
                )],
            ),
        ]);

        $this->actingAs($user)->post(route('games.round-bonus-action', $game), [
            'discipline' => KnowledgeDiscipline::Law->value,
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->knowledge->law);
        $this->assertSame([RoundBonus::Knowledge->value], $game->state->players[0]->usedSpecialActionIds);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame(GameActionType::SpecialAction, $game->actions()->sole()->type);

        $this->post(route('games.round-bonus-action', $game), [
            'discipline' => KnowledgeDiscipline::Law->value,
        ])->assertSessionHasErrors('round_bonus');

        $this->post(route('games.current-turn.restart', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->knowledge->law);
        $this->assertSame([], $game->state->players[0]->usedSpecialActionIds);
    }

    public function test_round_bonus_knowledge_action_requires_a_discipline(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $game->update([
            'state' => new GameStateData(
                turnOrder: [$player->id],
                round: new RoundStateData(phase: GamePhase::Actions),
                players: [new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Knowledge,
                )],
            ),
        ]);

        $this->actingAs($user)->post(route('games.round-bonus-action', $game))
            ->assertSessionHasErrors('discipline');

        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->knowledge->law);
        $this->assertCount(0, $game->actions);
    }

    public function test_philosophers_can_choose_a_book_and_restart_the_action(): void
    {
        [$game, $user] = $this->gameForFactionAction(Faction::Philosophers);

        $this->actingAs($user)->post(route('games.faction-action', $game), [
            'discipline' => KnowledgeDiscipline::Engineering->value,
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->resources->books->engineering);
        $this->assertSame(
            [Faction::Philosophers->specialActionId()],
            $game->state->players[0]->usedSpecialActionIds,
        );
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame(GameActionType::SpecialAction, $game->actions()->sole()->type);

        $this->post(route('games.faction-action', $game), [
            'discipline' => KnowledgeDiscipline::Law->value,
        ])->assertSessionHasErrors('faction');

        $this->post(route('games.current-turn.restart', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->resources->books->engineering);
        $this->assertSame([], $game->state->players[0]->usedSpecialActionIds);
    }

    public function test_palace_resource_action_can_be_confirmed_only_once_and_restarted(): void
    {
        [$game, $user] = $this->gameForPalaceAction(PalaceAbility::Palace01);

        $this->actingAs($user)->post(route('games.palace-action', $game))
            ->assertRedirect(route('games.show', $game));
        $game->refresh();
        $this->assertSame(2, $game->state->players[0]->resources->tools);
        $this->assertContains(PalaceAbility::Palace01->specialActionId(), $game->state->players[0]->usedSpecialActionIds);

        $this->post(route('games.palace-action', $game))->assertSessionHasErrors('palace');
        $this->post(route('games.current-turn.restart', $game));
        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertSame([], $game->state->players[0]->usedSpecialActionIds);
    }

    public function test_palace_knowledge_action_distributes_two_steps_between_disciplines(): void
    {
        [$game, $user] = $this->gameForPalaceAction(PalaceAbility::Palace06);
        $state = $game->state;
        $state->round->scoringTileId = RoundScoringTile::KnowledgeMedicine->value;
        $game->update(['state' => $state]);

        $this->actingAs($user)->post(route('games.palace-action', $game))
            ->assertSessionHasErrors('knowledge_steps');
        $this->post(route('games.palace-action', $game), [
            'knowledge_steps' => [
                KnowledgeDiscipline::Banking->value => 0,
                KnowledgeDiscipline::Law->value => 1,
                KnowledgeDiscipline::Engineering->value => 0,
                KnowledgeDiscipline::Medicine->value => 1,
            ],
        ]);
        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->knowledge->law);
        $this->assertSame(1, $game->state->players[0]->knowledge->medicine);
        $this->assertSame(22, $game->state->players[0]->victoryPoints);
        $this->assertSame(2, $game->actions()->sole()->payload['victory_points']);
        $this->assertSame([
            KnowledgeDiscipline::Law->value,
            KnowledgeDiscipline::Medicine->value,
        ], $game->actions()->sole()->payload['knowledge_disciplines']);
    }

    public function test_palace_knowledge_action_may_apply_both_steps_to_one_discipline(): void
    {
        [$game, $user] = $this->gameForPalaceAction(PalaceAbility::Palace06);

        $this->actingAs($user)->post(route('games.palace-action', $game), [
            'knowledge_steps' => [KnowledgeDiscipline::Engineering->value => 2],
        ]);
        $game->refresh();

        $this->assertSame(2, $game->state->players[0]->knowledge->engineering);
    }

    public function test_palace_knowledge_action_scores_only_steps_that_were_actually_advanced(): void
    {
        [$game, $user] = $this->gameForPalaceAction(PalaceAbility::Palace06);
        $state = $game->state;
        $state->round->scoringTileId = RoundScoringTile::KnowledgeMedicine->value;
        $state->players[0]->knowledge->banking = 7;
        $game->update(['state' => $state]);

        $this->actingAs($user)->post(route('games.palace-action', $game), [
            'knowledge_steps' => [
                KnowledgeDiscipline::Banking->value => 1,
                KnowledgeDiscipline::Medicine->value => 1,
            ],
        ]);
        $game->refresh();

        $this->assertSame(7, $game->state->players[0]->knowledge->banking);
        $this->assertSame(1, $game->state->players[0]->knowledge->medicine);
        $this->assertSame(21, $game->state->players[0]->victoryPoints);
        $this->assertSame(1, $game->actions()->sole()->payload['victory_points']);
    }

    public function test_palace_spade_action_starts_terraforming_with_two_spades(): void
    {
        [$game, $user] = $this->gameForPalaceAction(PalaceAbility::Palace02);
        $state = $game->state;
        $state->board = new BoardStateData(hexes: [
            new BoardHexStateData(
                id: '0:0',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                adjacentHexIds: ['1:0'],
                building: new BuildingStateData(BuildingType::Workshop, $state->players[0]->playerId),
            ),
            new BoardHexStateData(
                id: '1:0',
                q: 1,
                r: 0,
                initialTerrain: TerrainType::Mountain,
                terrain: TerrainType::Mountain,
                adjacentHexIds: ['0:0'],
            ),
        ]);
        $game->update(['state' => $state]);

        $this->actingAs($user)->post(route('games.palace-action', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(2, $game->state->players[0]->unassignedSpades);
        $this->assertSame(PendingInteractionType::SpendSpades, $game->state->pendingInteraction?->type);
        $this->assertSame(['1:0'], $game->state->pendingInteraction?->optionIds);
        $this->assertSame(2, $game->state->pendingInteraction?->context['remainingSpades']);
    }

    public function test_palace_can_replace_a_school_with_a_guild(): void
    {
        [$game, $user] = $this->gameForPalaceAction(PalaceAbility::Palace03, BuildingType::School);

        $this->actingAs($user)->post(route('games.palace-action', $game), ['hex_id' => '0:0']);
        $game->refresh();
        $this->assertSame(BuildingType::Guild, $game->state->board->hexes[0]->building?->type);
        $this->assertSame(23, $game->state->players[0]->victoryPoints);
        $this->assertSame(1, $game->state->players[0]->resources->tools);
    }

    public function test_palace_can_upgrade_a_workshop_to_a_guild_for_free(): void
    {
        [$game, $user] = $this->gameForPalaceAction(PalaceAbility::Palace04, BuildingType::Workshop);
        $this->actingAs($user)->post(route('games.palace-action', $game), ['hex_id' => '0:0']);
        $game->refresh();
        $this->assertSame(BuildingType::Guild, $game->state->board->hexes[0]->building?->type);
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertSame(0, $game->state->players[0]->resources->coins);
    }

    public function test_palace_can_grant_coins_and_a_selected_book(): void
    {
        [$game, $user] = $this->gameForPalaceAction(PalaceAbility::Palace13);
        $this->actingAs($user)->post(route('games.palace-action', $game), ['discipline' => KnowledgeDiscipline::Law->value]);
        $game->refresh();
        $this->assertSame(3, $game->state->players[0]->resources->coins);
        $this->assertSame(1, $game->state->players[0]->resources->books->law);
    }

    public function test_player_can_pass_only_on_their_turn_and_choose_an_available_round_bonus(): void
    {
        [$game, $firstUser, $secondUser] = $this->gameForPassing();
        $state = $game->state;
        $state->round->hasTakenMainAction = true;
        $game->update(['state' => $state]);

        $this->actingAs($firstUser)->post(route('games.pass', $game), [
            'round_bonus' => RoundBonus::RiverWorkshop->value,
        ])->assertForbidden();

        $state->round->hasTakenMainAction = false;
        $game->update(['state' => $state]);

        $this->actingAs($secondUser)->post(route('games.pass', $game), [
            'round_bonus' => RoundBonus::RiverWorkshop->value,
        ])->assertForbidden();

        $this->actingAs($firstUser)->post(route('games.pass', $game), [
            'round_bonus' => RoundBonus::Spade->value,
        ])->assertSessionHasErrors('round_bonus');

        $this->post(route('games.pass', $game), [
            'round_bonus' => RoundBonus::RiverWorkshop->value,
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame($secondUser->id, $game->active_player_id);
        $this->assertSame(RoundBonus::RiverWorkshop, $game->state->players[0]->roundBonus);
        $this->assertSame([$game->state->players[0]->playerId], $game->state->passedPlayerIds);
        $this->assertSame([$game->state->players[0]->playerId], $game->state->round->passOrder);
        $this->assertSame(2, $game->state->players[0]->resources->coins);
        $this->assertContains(
            RoundBonus::Knowledge,
            array_column($game->state->setupPool?->availableRoundBonuses ?? [], 'roundBonus'),
        );
        $this->assertSame(GameActionType::Pass, $game->actions()->sole()->type);

        $this->get(route('games.show', $game))->assertInertia(
            fn (Assert $page) => $page
                ->where('game.data.playerBoardStates.0.passOrder', 1)
                ->where('game.data.canPass', false),
        );
    }

    public function test_last_pass_sets_the_next_round_turn_order_and_starts_the_next_round(): void
    {
        [$game, $firstUser, $secondUser] = $this->gameForPassing();
        $firstPlayerId = $game->state->players[0]->playerId;
        $secondPlayerId = $game->state->players[1]->playerId;

        $this->actingAs($firstUser)->post(route('games.pass', $game), [
            'round_bonus' => RoundBonus::RiverWorkshop->value,
        ])->assertRedirect(route('games.show', $game))->assertSessionHasNoErrors();
        $this->actingAs($secondUser)->post(route('games.pass', $game), [
            'round_bonus' => RoundBonus::BuildGuild->value,
        ])->assertRedirect(route('games.show', $game))->assertSessionHasNoErrors();

        $game->refresh();
        $this->assertSame(2, $game->state->round->number);
        $this->assertSame([$firstPlayerId, $secondPlayerId], $game->state->turnOrder);
        $this->assertSame([$firstPlayerId, $secondPlayerId], $game->state->round->passOrder);
        $this->assertSame([], $game->state->passedPlayerIds);
        $this->assertSame(GamePhase::Actions, $game->phase);
        $this->assertSame($firstUser->id, $game->active_player_id);
        $this->assertSame(2, $game->actions()->count());
    }

    public function test_pass_awards_victory_points_from_all_pass_bonus_sources(): void
    {
        [$game, $firstUser] = $this->gameForPassing();
        $state = $game->state;
        $player = $state->players[0];
        $player->roundBonus = RoundBonus::PassPalaceUniversity;
        $player->competencyIds = [Competency::Competency08->value, Competency::Competency12->value];
        $player->palaceId = PalaceAbility::Palace07->value;
        $player->inventionIds = [Innovation::TradeRoutes->value];
        $player->townTileIds = ['town_01', 'town_02'];
        $player->knowledge = new KnowledgeStateData(banking: 5, law: 2, engineering: 4, medicine: 3);
        $buildingTypes = [
            BuildingType::Palace,
            BuildingType::University,
            BuildingType::School,
            BuildingType::School,
            BuildingType::Guild,
            BuildingType::Guild,
            BuildingType::Guild,
        ];
        $state->board = new BoardStateData(hexes: array_map(
            static fn (BuildingType $buildingType, int $index): BoardHexStateData => new BoardHexStateData(
                id: "{$index}:0",
                q: $index,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                building: new BuildingStateData($buildingType, $player->playerId),
            ),
            $buildingTypes,
            array_keys($buildingTypes),
        ));
        $game->update(['state' => $state]);

        $this->actingAs($firstUser)->post(route('games.pass', $game), [
            'round_bonus' => RoundBonus::RiverWorkshop->value,
        ])->assertRedirect(route('games.show', $game))->assertSessionHasNoErrors();

        $game->refresh();
        $this->assertSame(46, $game->state->players[0]->victoryPoints);
        $action = $game->actions()->sole();
        $this->assertSame(26, $action->payload['victory_points']);
        $this->assertCount(5, $action->payload['scoring_sources']);
        $this->assertSame([8, 4, 2, 6, 6], array_column($action->payload['scoring_sources'], 'points'));

    }

    public function test_players_choose_science_bonus_books_in_pass_order_before_the_next_round(): void
    {
        [$game, $firstUser, $secondUser] = $this->gameForPassing();
        $state = $game->state;
        $state->round->scoringTileId = RoundScoringTile::GuildLaw->value;
        $state->setupPool->roundScoringTiles[0] = RoundScoringTile::GuildLaw;
        $state->players[0]->knowledge->law = 6;
        $game->update(['state' => $state]);

        $this->actingAs($firstUser)->post(route('games.pass', $game), [
            'round_bonus' => RoundBonus::RiverWorkshop->value,
        ]);
        $this->actingAs($secondUser)->post(route('games.pass', $game), [
            'round_bonus' => RoundBonus::BuildGuild->value,
        ]);

        $game->refresh();
        $this->assertSame(GamePhase::ScienceBonus, $game->phase);
        $this->assertSame($firstUser->id, $game->active_player_id);
        $this->assertSame(PendingInteractionType::ChooseScienceBonusBooks, $game->state->pendingInteraction?->type);
        $this->assertSame(2, $game->state->pendingInteraction?->context['bookCount']);

        $this->actingAs($firstUser)->post(route('games.books', $game), [
            'book_counts' => ['banking' => 3, 'law' => 0, 'engineering' => 0, 'medicine' => 0],
        ])->assertSessionHasErrors('book_counts.banking');

        $this->actingAs($firstUser)->post(route('games.books', $game), [
            'book_counts' => ['banking' => 1, 'law' => 0, 'engineering' => 0, 'medicine' => 1],
        ])->assertRedirect(route('games.show', $game))->assertSessionHasNoErrors();

        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->resources->books->banking);
        $this->assertSame(1, $game->state->players[0]->resources->books->medicine);
        $this->assertSame(2, $game->state->round->number);
        $this->assertSame(GamePhase::Actions, $game->phase);
        $this->assertSame($firstUser->id, $game->active_player_id);
    }

    public function test_automatic_science_bonuses_are_applied_before_the_next_round(): void
    {
        [$game, $firstUser, $secondUser] = $this->gameForPassing();
        $state = $game->state;
        $state->round->scoringTileId = RoundScoringTile::PalaceUniversityBanking->value;
        $state->setupPool->roundScoringTiles[0] = RoundScoringTile::PalaceUniversityBanking;
        $state->players[0]->knowledge->banking = 4;
        $state->players[1]->knowledge->banking = 6;
        $game->update(['state' => $state]);

        $this->actingAs($firstUser)->post(route('games.pass', $game), ['round_bonus' => RoundBonus::RiverWorkshop->value]);
        $this->actingAs($secondUser)->post(route('games.pass', $game), ['round_bonus' => RoundBonus::BuildGuild->value]);

        $game->refresh();
        $this->assertSame(3, $game->state->players[0]->resources->tools);
        $this->assertSame(4, $game->state->players[1]->resources->tools);
        $this->assertSame(2, $game->state->round->number);
        $this->assertSame(GamePhase::Actions, $game->phase);
    }

    public function test_player_can_confirm_or_rollback_a_science_bonus_spade(): void
    {
        [$game, $firstUser, $secondUser] = $this->gameForPassing();
        $state = $game->state;
        $state->round->scoringTileId = RoundScoringTile::GuildMedicine->value;
        $state->setupPool->roundScoringTiles[0] = RoundScoringTile::GuildMedicine;
        $state->players[0]->knowledge->medicine = 4;
        $state->board = new BoardStateData(hexes: [
            new BoardHexStateData(
                id: '0:0',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                adjacentHexIds: ['1:0'],
                building: new BuildingStateData(BuildingType::Workshop, $state->players[0]->playerId),
            ),
            new BoardHexStateData(
                id: '1:0',
                q: 1,
                r: 0,
                initialTerrain: TerrainType::Mountain,
                terrain: TerrainType::Mountain,
                adjacentHexIds: ['0:0'],
            ),
        ]);
        $game->update(['state' => $state]);

        $this->actingAs($firstUser)->post(route('games.pass', $game), ['round_bonus' => RoundBonus::RiverWorkshop->value]);
        $this->actingAs($secondUser)->post(route('games.pass', $game), ['round_bonus' => RoundBonus::BuildGuild->value]);
        $game->refresh();
        $this->assertSame(GamePhase::ScienceBonus, $game->phase);
        $this->assertSame(PendingInteractionType::SpendSpades, $game->state->pendingInteraction?->type);

        $this->actingAs($firstUser)->post(route('games.paid-terraforming', $game), [
            'hex_id' => '1:0',
            'use_available' => false,
        ])->assertRedirect(route('games.show', $game));
        $this->delete(route('games.starting-spade.destroy', $game));
        $game->refresh();
        $this->assertSame(TerrainType::Mountain, $game->state->board->hexes[1]->terrain);

        $this->post(route('games.starting-spade.store', $game), ['hex_id' => '1:0']);
        $this->post(route('games.starting-spade.finish', $game))->assertRedirect(route('games.show', $game));
        $game->refresh();
        $this->assertSame(TerrainType::Forest, $game->state->board->hexes[1]->terrain);
        $this->assertSame(0, $game->state->players[0]->unassignedSpades);
        $this->assertSame(2, $game->state->round->number);
        $this->assertNull($game->state->turnStartSnapshot);
        $this->assertNull($game->state->round->turnStartVersion);
        $this->get(route('games.show', $game))->assertInertia(
            fn (Assert $page) => $page->where('game.data.canRestartCurrentTurn', false),
        );
    }

    public function test_psychics_gain_power_without_spending_the_main_action(): void
    {
        [$game, $user] = $this->gameForFactionAction(Faction::Psychics);

        $this->actingAs($user)->post(route('games.faction-action', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(5, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertFalse($game->state->round->hasTakenMainAction);
        $this->assertSame(
            [Faction::Psychics->specialActionId()],
            $game->state->players[0]->usedSpecialActionIds,
        );

        $this->post(route('games.current-turn.restart', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame([], $game->state->players[0]->usedSpecialActionIds);
    }

    public function test_competency_seven_gains_power_only_once_and_can_be_restarted(): void
    {
        [$game, $user] = $this->gameForFactionAction(Faction::Blessed);
        $state = $game->state;
        $state->players[0]->competencyIds = [Competency::Competency07->value];
        $game->update(['state' => $state]);

        $this->actingAs($user)->post(route('games.competency-action', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->resources->power->bowlOne);
        $this->assertSame(4, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlThree);
        $this->assertFalse($game->state->round->hasTakenMainAction);
        $this->assertSame(
            [Competency::Competency07->value],
            $game->state->players[0]->usedSpecialActionIds,
        );
        $this->assertSame(Competency::Competency07->value, $game->actions()->sole()->payload['competency']);

        $this->post(route('games.competency-action', $game))
            ->assertSessionHasErrors('competency');

        $this->post(route('games.current-turn.restart', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(5, $game->state->players[0]->resources->power->bowlOne);
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame([], $game->state->players[0]->usedSpecialActionIds);
    }

    public function test_player_can_activate_a_book_action_only_once_per_round(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $setupPool = app(GameSetupPoolFactory::class)->create(2);
        $setupPool->bookActions = [
            BookAction::GainCoins,
            BookAction::GainPower,
            BookAction::ScoreGuilds,
        ];
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            round: new RoundStateData(
                phase: GamePhase::Actions,
                usedSharedActionIds: [PowerAction::GainCoins->value],
            ),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(
                    books: new BookSupplyData(banking: 1, law: 1),
                ),
            )],
            setupPool: $setupPool,
        )]);

        $this->actingAs($user)
            ->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->has('game.data.bookActionStates', 3)
                    ->where('game.data.bookActionStates.0.id', BookAction::GainCoins->value)
                    ->where('game.data.bookActionStates.0.cost', 2)
                    ->where('game.data.bookActionStates.0.isUsed', false),
            );

        $payload = [
            'action' => BookAction::GainCoins->value,
            'book_counts' => [
                'banking' => 1,
                'law' => 1,
                'engineering' => 0,
                'medicine' => 0,
            ],
        ];
        $this->actingAs($user)
            ->post(route('games.book-action', $game), $payload)
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(6, $game->state->players[0]->resources->coins);
        $this->assertSame(0, $game->state->players[0]->resources->books->banking);
        $this->assertSame(0, $game->state->players[0]->resources->books->law);
        $this->assertContains(BookAction::GainCoins->value, $game->state->round->usedBookActionIds);
        $this->assertSame(GameActionType::BookAction, $game->actions()->sole()->type);

        $this->post(route('games.book-action', $game), $payload)->assertSessionHasErrors('action');
        $this->assertSame(1, $game->actions()->count());
    }

    public function test_power_action_is_not_applied_when_power_cannot_be_sacrificed(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $game->update([
            'state' => new GameStateData(
                turnOrder: [$player->id],
                round: new RoundStateData(phase: GamePhase::Actions),
                players: [new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlTwo: 2, bowlThree: 2),
                    ),
                )],
            ),
        ]);

        $this->actingAs($user)
            ->post(route('games.power-action', $game), [
                'action' => PowerAction::GainTools->value,
                'sacrifice_amount' => 2,
            ])
            ->assertSessionHasErrors('sacrifice_amount');

        $game->refresh();
        $this->assertSame(2, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame(2, $game->state->players[0]->resources->power->bowlThree);
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertSame(0, $game->actions()->count());
    }

    public function test_power_bridge_can_be_selected_rolled_back_and_confirmed(): void
    {
        [$game, $user] = $this->gameForBridgeAction();

        $this->actingAs($user)->post(route('games.power-action', $game), [
            'action' => PowerAction::BuildBridge->value,
            'sacrifice_amount' => 0,
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(PendingInteractionType::PlaceBridge, $game->state->pendingInteraction?->type);
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlThree);

        $state = $game->state;
        $state->pendingInteraction->context['pairs'] = [[
            'fromHexId' => '8:5',
            'toHexId' => '6:7',
        ], [
            'fromHexId' => '8:5',
            'toHexId' => '8:7',
        ]];
        $game->update(['state' => $state]);

        $this->post(route('games.bridge.store', $game), [
            'from_hex_id' => '0:0',
            'to_hex_id' => '3:0',
        ])->assertSessionHasErrors('bridge');
        $game->refresh();
        $this->assertCount(0, $game->state->board->bridges);

        $bridge = ['from_hex_id' => '8:5', 'to_hex_id' => '7:7'];
        $this->post(route('games.bridge.store', $game), $bridge)
            ->assertRedirect(route('games.show', $game));
        $game->refresh();
        $this->assertSame('8:5', $game->state->pendingInteraction?->context['selectedFromHexId']);

        $this->delete(route('games.bridge.destroy', $game))
            ->assertRedirect(route('games.show', $game));
        $game->refresh();
        $this->assertArrayNotHasKey('selectedFromHexId', $game->state->pendingInteraction?->context ?? []);

        $this->post(route('games.bridge.store', $game), $bridge);
        $this->post(route('games.bridge.confirm', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertCount(1, $game->state->board->bridges);
        $this->assertSame('8:5', $game->state->board->bridges[0]->fromHexId);
        $this->assertSame('7:7', $game->state->board->bridges[0]->toHexId);
        $this->assertSame(
            [GameActionType::PowerAction],
            $game->actions()->orderBy('sequence')->pluck('type')->all(),
        );
    }

    public function test_round_bonus_bridge_starts_the_same_bridge_interaction(): void
    {
        [$game, $user] = $this->gameForBridgeAction(RoundBonus::Bridge);

        $this->actingAs($user)->post(route('games.round-bonus-action', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(PendingInteractionType::PlaceBridge, $game->state->pendingInteraction?->type);
        $this->assertContains(RoundBonus::Bridge->value, $game->state->players[0]->usedSpecialActionIds);

        $this->post(route('games.current-turn.restart', $game))
            ->assertRedirect(route('games.show', $game));
        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame([], $game->state->players[0]->usedSpecialActionIds);
    }

    public function test_power_action_spades_can_be_selected_rolled_back_and_confirmed_immediately(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $buildingHex = new BoardHexStateData(
            id: '0:0',
            q: 0,
            r: 0,
            initialTerrain: TerrainType::Forest,
            terrain: TerrainType::Forest,
            adjacentHexIds: ['1:0'],
            building: new BuildingStateData(BuildingType::Workshop, $player->id),
        );
        $targetHex = new BoardHexStateData(
            id: '1:0',
            q: 1,
            r: 0,
            initialTerrain: TerrainType::Desert,
            terrain: TerrainType::Desert,
            adjacentHexIds: ['0:0'],
        );
        $game->update([
            'state' => new GameStateData(
                turnOrder: [$player->id],
                board: new BoardStateData(hexes: [$buildingHex, $targetHex]),
                round: new RoundStateData(phase: GamePhase::Actions),
                players: [new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlThree: 6),
                    ),
                )],
            ),
        ]);

        $this->actingAs($user)->post(route('games.power-action', $game), [
            'action' => PowerAction::TerraformTwoSpades->value,
            'sacrifice_amount' => 0,
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(PendingInteractionType::SpendSpades, $game->state->pendingInteraction?->type);
        $this->assertSame(GamePhase::Actions->value, $game->state->pendingInteraction?->context['phase']);
        $this->assertSame(2, $game->state->players[0]->unassignedSpades);

        $this->post(route('games.paid-terraforming', $game), ['hex_id' => '1:0'])
            ->assertSessionHasErrors('hex_id');

        $this->post(route('games.paid-terraforming', $game), [
            'hex_id' => '1:0',
            'use_available' => true,
        ])
            ->assertRedirect(route('games.show', $game));
        $game->refresh();
        $this->assertSame(TerrainType::Mountain, $game->state->board->hexes[1]->terrain);
        $this->assertSame(0, $game->state->players[0]->resources->tools);

        $this->delete(route('games.starting-spade.destroy', $game))
            ->assertRedirect(route('games.show', $game));
        $game->refresh();
        $this->assertSame(TerrainType::Desert, $game->state->board->hexes[1]->terrain);

        $this->post(route('games.paid-terraforming', $game), [
            'hex_id' => '1:0',
            'use_available' => true,
        ]);
        $this->post(route('games.starting-spade.finish', $game));
        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(GamePhase::Actions, $game->phase);
        $this->assertSame(0, $game->state->players[0]->unassignedSpades);
        $this->assertSame(TerrainType::Mountain, $game->state->board->hexes[1]->terrain);
    }

    public function test_player_can_build_a_workshop_on_reachable_homeland_using_navigation(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['0:1'],
                    building: new BuildingStateData(BuildingType::Workshop, $player->id),
                ),
                new BoardHexStateData(
                    id: '0:1',
                    q: 0,
                    r: 1,
                    initialTerrain: TerrainType::Water,
                    terrain: TerrainType::Water,
                    adjacentHexIds: ['0:0', '0:2'],
                ),
                new BoardHexStateData(
                    id: '0:2',
                    q: 0,
                    r: 2,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['0:1'],
                ),
            ]),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 1, coins: 2),
                shippingLevel: 1,
            )],
        )]);

        $this->actingAs($user)->post(route('games.workshop', $game), ['hex_id' => '0:2'])
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(BuildingType::Workshop, $game->state->board->hexes[2]->building?->type);
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertSame(0, $game->state->players[0]->resources->coins);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame(GameActionType::BuildWorkshop, $game->actions()->sole()->type);

        $this->post(route('games.current-turn.restart', $game));
        $game->refresh();

        $this->assertNull($game->state->board->hexes[2]->building);
        $this->assertSame(1, $game->state->players[0]->resources->tools);
        $this->assertSame(2, $game->state->players[0]->resources->coins);
    }

    public function test_player_can_build_a_workshop_on_homeland_connected_by_their_bridge(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(
                hexes: [
                    new BoardHexStateData(
                        id: '0:0',
                        q: 0,
                        r: 0,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                        building: new BuildingStateData(BuildingType::Workshop, $player->id),
                    ),
                    new BoardHexStateData(
                        id: '0:2',
                        q: 0,
                        r: 2,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                    ),
                ],
                bridges: [new BridgeStateData('0:0', '0:2', $player->id)],
            ),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 1, coins: 2),
            )],
        )]);

        $this->actingAs($user)->post(route('games.workshop', $game), ['hex_id' => '0:2'])
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(BuildingType::Workshop, $game->state->board->hexes[1]->building?->type);
        $this->assertSame($player->id, $game->state->board->hexes[1]->building?->ownerPlayerId);
    }

    public function test_building_an_eligible_group_founds_a_town_and_player_chooses_its_tile(): void
    {
        $user = User::factory()->create();
        $neighborUser = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id, 'seat' => 1]);
        $neighbor = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $neighborUser->id, 'seat' => 2]);
        $hexes = [];

        foreach ([BuildingType::Guild, BuildingType::University, BuildingType::Workshop] as $index => $buildingType) {
            $hexes[] = new BoardHexStateData(
                id: "{$index}:0",
                q: $index,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                adjacentHexIds: array_values(array_filter([($index - 1).':0', ($index + 1).':0'])),
                building: new BuildingStateData($buildingType, $player->id),
            );
        }

        $hexes[] = new BoardHexStateData(
            id: '3:0',
            q: 3,
            r: 0,
            initialTerrain: TerrainType::Forest,
            terrain: TerrainType::Forest,
            adjacentHexIds: ['2:0', '3:1'],
        );
        $hexes[] = new BoardHexStateData(
            id: '3:1',
            q: 3,
            r: 1,
            initialTerrain: TerrainType::Desert,
            terrain: TerrainType::Desert,
            adjacentHexIds: ['3:0'],
            building: new BuildingStateData(BuildingType::Guild, $neighbor->id),
        );
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id, $neighbor->id],
            board: new BoardStateData(hexes: $hexes),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [
                new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(tools: 1, coins: 2),
                ),
                new GamePlayerStateData(
                    playerId: $neighbor->id,
                    userId: $neighborUser->id,
                    color: PlayerColor::Red,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Desert,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlTwo: 2),
                    ),
                ),
            ],
            availableTownTileIds: array_merge(...array_fill(0, 3, array_column(TownTile::cases(), 'value'))),
        )]);

        $this->actingAs($user)->post(route('games.workshop', $game), ['hex_id' => '3:0']);
        $game->refresh();

        $this->assertSame(PendingInteractionType::PowerOffer, $game->state->pendingInteraction?->type);
        $this->assertSame($neighborUser->id, $game->active_player_id);

        $this->actingAs($neighborUser)->post(route('games.power-offer', $game), ['accept' => false]);
        $game->refresh();

        $this->assertSame(PendingInteractionType::ChooseTown, $game->state->pendingInteraction?->type);
        $this->assertSame($user->id, $game->active_player_id);
        $this->assertCount(7, $game->state->pendingInteraction?->optionIds);
        $this->actingAs($user)
            ->get(route('games.show', $game))
            ->assertInertia(fn (Assert $page) => $page->where('game.data.canRestartCurrentTurn', true));

        $this->actingAs($user)->post(route('games.town', $game), ['town_tile' => TownTile::Tools->value])
            ->assertRedirect(route('games.show', $game));
        $game->refresh();

        $this->assertSame([TownTile::Tools->value], $game->state->players[0]->townTileIds);
        $this->assertSame(3, $game->state->players[0]->resources->tools);
        $this->assertSame(24, $game->state->players[0]->victoryPoints);
        $this->assertCount(20, $game->state->availableTownTileIds);
        $this->assertCount(1, array_unique(array_filter(array_column($game->state->board->hexes, 'townId'))));
        $this->assertSame(TownTile::Tools->value, $game->state->board->hexes[3]->townTileId);
        $this->assertSame([
            GameActionType::BuildWorkshop,
            GameActionType::DeclinePower,
            GameActionType::ChooseTown,
        ], $game->actions()->orderBy('sequence')->pluck('type')->all());
        $this->get(route('games.show', $game))
            ->assertInertia(fn (Assert $page) => $page
                ->where('game.data.canUndoTownChoice', true)
                ->where('game.data.canFinishCurrentTurn', true));

        $this->delete(route('games.town-choice.destroy', $game))
            ->assertRedirect(route('games.show', $game));
        $game->refresh();

        $this->assertSame(PendingInteractionType::ChooseTown, $game->state->pendingInteraction?->type);
        $this->assertSame([], $game->state->players[0]->townTileIds);
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertSame(20, $game->state->players[0]->victoryPoints);
        $this->assertCount(21, $game->state->availableTownTileIds);
        $this->assertCount(0, array_filter(array_column($game->state->board->hexes, 'townId')));
        $this->assertSame([
            GameActionType::BuildWorkshop,
            GameActionType::DeclinePower,
        ], $game->actions()->orderBy('sequence')->pluck('type')->all());

        $this->post(route('games.town', $game), ['town_tile' => TownTile::Coins->value]);
        $game->refresh();

        $this->assertSame([TownTile::Coins->value], $game->state->players[0]->townTileIds);
        $this->assertSame(6, $game->state->players[0]->resources->coins);
        $this->assertSame(TownTile::Coins->value, $game->state->board->hexes[3]->townTileId);
    }

    public function test_town_requirements_account_for_special_buildings_annexes_bridges_and_palace(): void
    {
        $findEligibleTownHexes = $this->app->make(FindEligibleTownHexesAction::class);
        $player = new GamePlayerStateData(
            playerId: 1,
            userId: 1,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
        );
        $stateFor = static function (array $buildings, array $bridges = []) use ($player): GameStateData {
            $hexes = array_map(
                static fn (BuildingStateData $building, int $index): BoardHexStateData => new BoardHexStateData(
                    id: "{$index}:0",
                    q: $index,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: $index === 0 ? ['1:0'] : ($index === count($buildings) - 1 ? [($index - 1).':0'] : [($index - 1).':0', ($index + 1).':0']),
                    building: $building,
                ),
                $buildings,
                array_keys($buildings),
            );

            return new GameStateData(
                board: new BoardStateData(hexes: $hexes, bridges: $bridges),
                players: [$player],
            );
        };

        $universityState = $stateFor([
            new BuildingStateData(BuildingType::University, 1),
            new BuildingStateData(BuildingType::Guild, 1),
            new BuildingStateData(BuildingType::Guild, 1),
        ]);
        $this->assertCount(3, $findEligibleTownHexes->execute($universityState, $player, '2:0'));

        $monumentState = $stateFor([
            new BuildingStateData(BuildingType::Monument, 1),
            new BuildingStateData(BuildingType::Palace, 1),
        ]);
        $this->assertCount(2, $findEligibleTownHexes->execute($monumentState, $player, '1:0'));

        $annexState = $stateFor([
            new BuildingStateData(BuildingType::Guild, 1, hasAnnex: true),
            new BuildingStateData(BuildingType::Guild, 1),
            new BuildingStateData(BuildingType::Guild, 1),
        ]);
        $this->assertCount(3, $findEligibleTownHexes->execute($annexState, $player, '2:0'));

        $player->palaceId = PalaceAbility::Palace08->value;
        $palaceState = $stateFor([
            new BuildingStateData(BuildingType::Palace, 1),
            new BuildingStateData(BuildingType::Workshop, 1),
            new BuildingStateData(BuildingType::Workshop, 1),
            new BuildingStateData(BuildingType::Workshop, 1),
        ]);
        $this->assertCount(4, $findEligibleTownHexes->execute($palaceState, $player, '3:0'));

        $bridgeState = $stateFor([
            new BuildingStateData(BuildingType::Palace, 1),
            new BuildingStateData(BuildingType::Guild, 1),
            new BuildingStateData(BuildingType::Workshop, 2),
            new BuildingStateData(BuildingType::Workshop, 1),
            new BuildingStateData(BuildingType::Workshop, 1),
        ], [new BridgeStateData('1:0', '3:0', 1)]);
        $bridgeState->board->hexes[1]->adjacentHexIds = ['0:0'];
        $bridgeState->board->hexes[3]->adjacentHexIds = ['4:0'];
        $this->assertCount(4, $findEligibleTownHexes->execute($bridgeState, $player, '4:0'));
    }

    public function test_knowledge_town_tile_scores_each_actual_knowledge_step_for_the_round_goal(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $game->update(['state' => new GameStateData(
            board: new BoardStateData(hexes: [new BoardHexStateData(
                id: '0:0',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                building: new BuildingStateData(BuildingType::Workshop, $player->id),
            )]),
            round: new RoundStateData(
                phase: GamePhase::Actions,
                scoringTileId: RoundScoringTile::KnowledgeMedicine->value,
            ),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                knowledge: new KnowledgeStateData(medicine: 12),
            )],
            availableTownTileIds: [TownTile::Knowledge->value],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseTown,
                $player->id,
                [TownTile::Knowledge->value],
                ['townHexIds' => ['0:0'], 'builtHexId' => '0:0'],
            ),
        )]);

        $this->actingAs($user)->post(route('games.town', $game), [
            'town_tile' => TownTile::Knowledge->value,
        ])->assertRedirect(route('games.show', $game));
        $game->refresh();

        $this->assertSame(30, $game->state->players[0]->victoryPoints);
        $this->assertSame(1, $game->state->players[0]->knowledge->banking);
        $this->assertSame(1, $game->state->players[0]->knowledge->law);
        $this->assertSame(1, $game->state->players[0]->knowledge->engineering);
        $this->assertSame(12, $game->state->players[0]->knowledge->medicine);
        $this->assertSame(10, $game->actions()->sole()->payload['victory_points']);
    }

    public function test_player_must_distribute_exactly_two_books_from_a_town_tile(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $game->update(['state' => new GameStateData(
            board: new BoardStateData(hexes: [new BoardHexStateData(
                id: '0:0',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                building: new BuildingStateData(BuildingType::Workshop, $player->id),
            )]),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(books: new BookSupplyData(unassigned: 2)),
            )],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseTownBooks,
                $player->id,
                context: ['bookCount' => 2, 'builtHexId' => '0:0'],
            ),
        )]);

        $this->actingAs($user)->post(route('games.books', $game), [
            'book_counts' => ['banking' => 1, 'law' => 0, 'engineering' => 0, 'medicine' => 0],
        ])->assertSessionHasErrors('book_counts');

        $this->post(route('games.books', $game), [
            'book_counts' => ['banking' => 1, 'law' => 0, 'engineering' => 0, 'medicine' => 1],
        ])->assertRedirect(route('games.show', $game));
        $game->refresh();

        $this->assertSame(0, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(1, $game->state->players[0]->resources->books->banking);
        $this->assertSame(1, $game->state->players[0]->resources->books->medicine);
        $this->assertSame(GameActionType::ChooseTownBooks, $game->actions()->sole()->type);
    }

    public function test_palace_fourteen_player_may_accept_or_decline_a_town_through_water(): void
    {
        $createGame = function (): array {
            $user = User::factory()->create();
            $game = Game::factory()->create([
                'status' => GameStatus::Active,
                'phase' => GamePhase::Actions,
                'active_player_id' => $user->id,
            ]);
            $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
            $game->update(['state' => new GameStateData(
                turnOrder: [$player->id],
                board: new BoardStateData(hexes: [
                    new BoardHexStateData(
                        id: '0:0',
                        q: 0,
                        r: 0,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                        adjacentHexIds: ['1:0'],
                        building: new BuildingStateData(BuildingType::Palace, $player->id),
                    ),
                    new BoardHexStateData(
                        id: '1:0',
                        q: 1,
                        r: 0,
                        initialTerrain: TerrainType::Water,
                        terrain: TerrainType::Water,
                        adjacentHexIds: ['0:0', '2:0'],
                    ),
                    new BoardHexStateData(
                        id: '2:0',
                        q: 2,
                        r: 0,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                        adjacentHexIds: ['1:0', '3:0'],
                        building: new BuildingStateData(BuildingType::Guild, $player->id),
                    ),
                    new BoardHexStateData(
                        id: '3:0',
                        q: 3,
                        r: 0,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                        adjacentHexIds: ['2:0', '4:0'],
                        building: new BuildingStateData(BuildingType::Workshop, $player->id),
                    ),
                    new BoardHexStateData(
                        id: '4:0',
                        q: 4,
                        r: 0,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                        adjacentHexIds: ['3:0'],
                    ),
                ]),
                round: new RoundStateData(phase: GamePhase::Actions),
                players: [new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(tools: 2, coins: 4),
                    palaceId: PalaceAbility::Palace14->value,
                )],
                availableTownTileIds: array_merge(...array_fill(0, 3, array_column(TownTile::cases(), 'value'))),
            )]);

            return [$user, $game];
        };

        [$decliningUser, $decliningGame] = $createGame();
        $this->actingAs($decliningUser)->post(route('games.workshop', $decliningGame), ['hex_id' => '4:0']);
        $decliningGame->refresh();
        $this->assertSame(PendingInteractionType::OfferPalaceWaterTown, $decliningGame->state->pendingInteraction?->type);
        $this->assertSame(['1:0'], $decliningGame->state->pendingInteraction?->optionIds);

        $this->post(route('games.town.palace-water', $decliningGame), ['accept' => false]);
        $decliningGame->refresh();
        $this->assertNull($decliningGame->state->pendingInteraction);
        $this->assertSame(GameActionType::DeclinePalaceWaterTown, $decliningGame->actions()->latest('sequence')->first()?->type);

        [$acceptingUser, $acceptingGame] = $createGame();
        $this->actingAs($acceptingUser)->post(route('games.workshop', $acceptingGame), ['hex_id' => '4:0']);
        $this->post(route('games.town.palace-water', $acceptingGame), [
            'accept' => true,
            'water_hex_id' => '1:0',
        ]);
        $acceptingGame->refresh();
        $this->assertSame(PendingInteractionType::ChooseTown, $acceptingGame->state->pendingInteraction?->type);

        $this->post(route('games.town', $acceptingGame), ['town_tile' => TownTile::Coins->value]);
        $acceptingGame->refresh();
        $waterHex = collect($acceptingGame->state->board->hexes)->firstWhere('id', '1:0');
        $this->assertSame(TownTile::Coins->value, $waterHex?->townTileId);
        $this->assertNotNull($waterHex?->townId);
    }

    public function test_power_terraforming_offers_a_workshop_and_turn_can_be_finished_after_building(): void
    {
        $user = User::factory()->create();
        $nextUser = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id, 'seat' => 1]);
        $nextPlayer = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $nextUser->id, 'seat' => 2]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id, $nextPlayer->id],
            board: new BoardStateData(hexes: [new BoardHexStateData(
                id: '1:0',
                q: 1,
                r: 0,
                initialTerrain: TerrainType::Mountain,
                terrain: TerrainType::Forest,
            )]),
            round: new RoundStateData(phase: GamePhase::Actions, turnStartVersion: 0),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Mountain,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(coins: 4, tools: 2),
                unassignedSpades: 1,
            )],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::SpendSpades,
                $player->id,
                ['1:0'],
                [
                    'phase' => GamePhase::Actions->value,
                    'spadeCount' => 1,
                    'remainingSpades' => 1,
                    'targetTerrain' => TerrainType::Mountain->value,
                ],
            ),
        )]);

        $this->actingAs($user)->post(route('games.starting-spade.store', $game), ['hex_id' => '1:0']);
        $this->post(route('games.starting-spade.finish', $game));
        $game->refresh();
        $this->assertSame(PendingInteractionType::BuildWorkshopAfterTerraforming, $game->state->pendingInteraction?->type);

        $this->post(route('games.terraform-workshop', $game), ['build' => true, 'hex_id' => '1:0'])
            ->assertRedirect(route('games.show', $game));
        $game->refresh();
        $this->assertSame(BuildingType::Workshop, $game->state->board->hexes[0]->building?->type);
        $this->assertSame(1, $game->state->players[0]->resources->tools);
        $this->assertSame(2, $game->state->players[0]->resources->coins);
        $this->assertNull($game->state->pendingInteraction);

        $this->post(route('games.current-turn.finish', $game))
            ->assertRedirect(route('games.show', $game));
        $game->refresh();
        $this->assertSame($nextUser->id, $game->active_player_id);
        $this->assertNull($game->state->round->turnStartVersion);
        $this->assertSame(GameActionType::FinishTurn, $game->actions()->latest('sequence')->first()?->type);
    }

    public function test_player_can_decline_workshop_after_power_terraforming(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Mountain,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(coins: 2, tools: 1),
            )],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::BuildWorkshopAfterTerraforming,
                $player->id,
                ['1:0'],
            ),
        )]);

        $this->actingAs($user)->post(route('games.terraform-workshop', $game), ['build' => false]);
        $game->refresh();

        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(1, $game->state->players[0]->resources->tools);
        $this->assertSame(2, $game->state->players[0]->resources->coins);
        $this->assertFalse((bool) $game->actions()->latest('sequence')->first()?->payload['built']);
    }

    public function test_player_can_place_an_annex_from_the_building_dialog_and_restart_the_turn(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
        ]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [new BoardHexStateData(
                id: '0:0',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Mountain,
                terrain: TerrainType::Mountain,
                building: new BuildingStateData(BuildingType::Workshop, $player->id),
            )]),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Mountain,
                roundBonus: RoundBonus::Coins,
                availableAnnexes: 1,
            )],
        )]);

        $this->actingAs($user)
            ->post(route('games.annex.start', $game), ['hex_id' => '9:9'])
            ->assertSessionHasErrors('annex');
        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);

        $this->actingAs($user)->post(route('games.annex.start', $game), ['hex_id' => '0:0']);
        $game->refresh();

        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(0, $game->state->players[0]->availableAnnexes);
        $this->assertTrue($game->state->board->hexes[0]->building?->hasAnnex);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame(GameActionType::PlaceAnnex, $game->actions()->sole()->type);
        $this->assertSame('0:0', $game->actions()->sole()->payload['hex_id']);

        $this->post(route('games.current-turn.restart', $game));
        $game->refresh();

        $this->assertSame(1, $game->state->players[0]->availableAnnexes);
        $this->assertFalse($game->state->board->hexes[0]->building?->hasAnnex);
        $this->assertFalse($game->state->round->hasTakenMainAction);
        $this->assertSame(0, $game->actions()->count());
    }

    public function test_neighbors_resolve_power_offers_in_turn_order_after_a_workshop_is_built(): void
    {
        $builderUser = User::factory()->create();
        $firstNeighborUser = User::factory()->create();
        $secondNeighborUser = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $builderUser->id,
        ]);
        $builder = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $builderUser->id,
            'seat' => 1,
        ]);
        $firstNeighbor = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $firstNeighborUser->id,
            'seat' => 2,
        ]);
        $secondNeighbor = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $secondNeighborUser->id,
            'seat' => 3,
        ]);
        $targetHex = new BoardHexStateData(
            id: '0:0',
            q: 0,
            r: 0,
            initialTerrain: TerrainType::Mountain,
            terrain: TerrainType::Mountain,
            adjacentHexIds: ['1:0', '0:1', '-1:1'],
        );
        $firstWorkshopHex = new BoardHexStateData(
            id: '1:0',
            q: 1,
            r: 0,
            initialTerrain: TerrainType::Forest,
            terrain: TerrainType::Forest,
            building: new BuildingStateData(BuildingType::Workshop, $firstNeighbor->id),
        );
        $firstGuildHex = new BoardHexStateData(
            id: '0:1',
            q: 0,
            r: 1,
            initialTerrain: TerrainType::Forest,
            terrain: TerrainType::Forest,
            building: new BuildingStateData(BuildingType::Guild, $firstNeighbor->id),
        );
        $secondUniversityHex = new BoardHexStateData(
            id: '-1:1',
            q: -1,
            r: 1,
            initialTerrain: TerrainType::Desert,
            terrain: TerrainType::Desert,
            building: new BuildingStateData(BuildingType::University, $secondNeighbor->id),
        );
        $game->update(['state' => new GameStateData(
            turnOrder: [$builder->id, $firstNeighbor->id, $secondNeighbor->id],
            board: new BoardStateData(
                hexes: [
                    $targetHex,
                    $firstWorkshopHex,
                    $firstGuildHex,
                    $secondUniversityHex,
                ],
                riverBankHexIds: ['0:0'],
                edgeHexIds: ['0:0'],
            ),
            round: new RoundStateData(
                number: 6,
                phase: GamePhase::Actions,
                scoringTileId: RoundScoringTile::WorkshopLaw->value,
                additionalScoringTileId: FinalRoundScoringTile::EdgeWorkshop->value,
                turnStartVersion: 0,
            ),
            players: [
                new GamePlayerStateData(
                    playerId: $builder->id,
                    userId: $builderUser->id,
                    color: PlayerColor::Green,
                    faction: Faction::Navigators,
                    homeland: TerrainType::Mountain,
                    roundBonus: RoundBonus::RiverWorkshop,
                    resources: new PlayerResourcesData(coins: 2, tools: 1),
                    palaceId: PalaceAbility::Palace12->value,
                    competencyIds: [Competency::Competency11->value],
                    inventionIds: [Innovation::TradeRoutes->value],
                ),
                new GamePlayerStateData(
                    playerId: $firstNeighbor->id,
                    userId: $firstNeighborUser->id,
                    color: PlayerColor::Blue,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlOne: 1, bowlTwo: 3),
                    ),
                ),
                new GamePlayerStateData(
                    playerId: $secondNeighbor->id,
                    userId: $secondNeighborUser->id,
                    color: PlayerColor::Red,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Desert,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlTwo: 3),
                    ),
                ),
            ],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::BuildWorkshopAfterTerraforming,
                $builder->id,
                ['0:0'],
            ),
        )]);

        $this->actingAs($builderUser)->post(route('games.terraform-workshop', $game), [
            'build' => true,
            'hex_id' => '0:0',
        ]);
        $game->refresh();
        $this->assertSame(34, $game->state->players[0]->victoryPoints);
        $this->assertSame(1, $game->state->players[0]->resources->coins);
        $this->assertSame(14, $game->actions()->first()?->payload['victory_points']);
        $this->assertSame(1, $game->actions()->first()?->payload['bonus_coins']);
        $this->assertCount(6, $game->actions()->first()?->payload['scoring_sources']);
        $this->assertSame($firstNeighborUser->id, $game->active_player_id);
        $this->assertSame(PendingInteractionType::PowerOffer, $game->state->pendingInteraction?->type);
        $this->assertSame(3, $game->state->pendingInteraction?->context['powerAmount']);

        $this->actingAs($firstNeighborUser)->post(route('games.power-offer', $game), ['accept' => true]);
        $game->refresh();
        $this->assertSame(0, $game->state->players[1]->resources->power->bowlOne);
        $this->assertSame(2, $game->state->players[1]->resources->power->bowlTwo);
        $this->assertSame(2, $game->state->players[1]->resources->power->bowlThree);
        $this->assertSame(18, $game->state->players[1]->victoryPoints);
        $this->assertSame($secondNeighborUser->id, $game->active_player_id);
        $this->assertSame(3, $game->state->pendingInteraction?->context['powerAmount']);

        $this->actingAs($builderUser)
            ->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page->where('game.data.canUndoLastAction', true),
            );

        $this->actingAs($secondNeighborUser)->post(route('games.power-offer', $game), ['accept' => false]);
        $game->refresh();
        $this->assertSame(3, $game->state->players[2]->resources->power->bowlTwo);
        $this->assertSame(20, $game->state->players[2]->victoryPoints);
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame($builderUser->id, $game->active_player_id);
        $this->assertTrue($game->state->round->isCurrentTurnIrrevocable);
        $this->assertSame([
            GameActionType::TerraformAndBuild,
            GameActionType::AcceptPower,
            GameActionType::DeclinePower,
        ], $game->actions()->orderBy('sequence')->pluck('type')->all());

        $this->actingAs($builderUser)
            ->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('game.data.canRestartCurrentTurn', false)
                    ->where('game.data.canUndoLastAction', true),
            );
        $this->post(route('games.current-turn.restart', $game))->assertForbidden();

        $this->post(route('games.current-turn.finish', $game))
            ->assertRedirect(route('games.show', $game));
        $game->refresh();
        $this->assertFalse($game->state->round->isCurrentTurnIrrevocable);
        $this->assertSame($firstNeighborUser->id, $game->active_player_id);
    }

    public function test_player_can_upgrade_a_workshop_to_a_discounted_guild(): void
    {
        $user = User::factory()->create();
        $neighborUser = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id, 'seat' => 1]);
        $neighbor = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $neighborUser->id, 'seat' => 2]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id, $neighbor->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['1:0'],
                    building: new BuildingStateData(BuildingType::Workshop, $player->id),
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    adjacentHexIds: ['0:0'],
                    building: new BuildingStateData(BuildingType::Workshop, $neighbor->id),
                ),
            ]),
            round: new RoundStateData(
                phase: GamePhase::Actions,
                scoringTileId: RoundScoringTile::GuildLaw->value,
            ),
            players: [
                new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::BuildGuild,
                    resources: new PlayerResourcesData(coins: 2, tools: 2),
                    palaceId: PalaceAbility::Palace13->value,
                ),
                new GamePlayerStateData(
                    playerId: $neighbor->id,
                    userId: $neighborUser->id,
                    color: PlayerColor::Red,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Mountain,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(power: new PowerBowlsStateData(bowlTwo: 1)),
                ),
            ],
        )]);

        $this->actingAs($user)
            ->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page->where('game.data.buildingUpgrades', []),
            );
        $state = $game->state;
        $state->players[0]->resources->coins = 3;
        $game->update(['state' => $state]);

        $this->post(route('games.building-upgrade', $game), [
            'hex_id' => '0:0',
            'target' => BuildingType::Guild->value,
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(BuildingType::Guild, $game->state->board->hexes[0]->building?->type);
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertSame(0, $game->state->players[0]->resources->coins);
        $this->assertSame(29, $game->state->players[0]->victoryPoints);
        $this->assertSame(PendingInteractionType::PowerOffer, $game->state->pendingInteraction?->type);
        $this->assertSame($neighborUser->id, $game->active_player_id);
        $this->assertSame(GameActionType::UpgradeBuilding, $game->actions()->sole()->type);
        $this->assertSame(9, $game->actions()->sole()->payload['victory_points']);
        $this->assertCount(3, $game->actions()->sole()->payload['scoring_sources']);
    }

    #[DataProvider('competencyBuildingUpgradeProvider')]
    public function test_player_chooses_a_competency_after_building_a_school_or_university(
        BuildingType $sourceBuilding,
        BuildingType $targetBuilding,
        int $toolCost,
        int $coinCost,
    ): void {
        $user = User::factory()->create();
        $neighborUser = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $neighbor = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $neighborUser->id,
            'seat' => 2,
        ]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id, $neighbor->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['1:0'],
                    building: new BuildingStateData($sourceBuilding, $player->id),
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    adjacentHexIds: ['0:0'],
                    building: new BuildingStateData(BuildingType::Workshop, $neighbor->id),
                ),
            ]),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [
                new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(coins: $coinCost, tools: $toolCost),
                ),
                new GamePlayerStateData(
                    playerId: $neighbor->id,
                    userId: $neighborUser->id,
                    color: PlayerColor::Red,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Mountain,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlTwo: 2),
                    ),
                ),
            ],
            availableCompetencyIds: [Competency::Competency04->value],
        )]);

        $this->actingAs($user)->post(route('games.building-upgrade', $game), [
            'hex_id' => '0:0',
            'target' => $targetBuilding->value,
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(PendingInteractionType::ChooseCompetency, $game->state->pendingInteraction?->type);
        $this->assertSame([
            'reason' => 'building',
            'builtHexId' => '0:0',
            'buildingType' => $targetBuilding->value,
        ], $game->state->pendingInteraction?->context);
        $this->assertSame([Competency::Competency04->value], $game->state->pendingInteraction?->optionIds);
        $this->assertSame($user->id, $game->active_player_id);

        $this->post(route('games.starting-competency.store', $game), [
            'competency_id' => Competency::Competency04->value,
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertContains(Competency::Competency04->value, $game->state->players[0]->competencyIds);
        $this->assertSame(1, $game->state->players[0]->resources->tools);
        $this->assertSame(2, $game->state->players[0]->resources->coins);
        $this->assertSame(25, $game->state->players[0]->victoryPoints);
        $this->assertSame(PendingInteractionType::PowerOffer, $game->state->pendingInteraction?->type);
        $this->assertSame($neighborUser->id, $game->active_player_id);
        $this->assertSame([
            GameActionType::UpgradeBuilding,
            GameActionType::ChooseCompetency,
        ], $game->actions()->orderBy('sequence')->pluck('type')->all());
    }

    /** @return array<string, array{BuildingType, BuildingType, int, int}> */
    public static function competencyBuildingUpgradeProvider(): array
    {
        return [
            'school' => [BuildingType::Guild, BuildingType::School, 3, 5],
            'university' => [BuildingType::School, BuildingType::University, 5, 8],
        ];
    }

    #[DataProvider('neutralInnovationBuildingTerrainProvider')]
    public function test_competency_ten_places_a_neutral_tower_after_any_required_terraforming(
        TerrainType $targetTerrain,
        int $expectedToolCost,
    ): void {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id, 'seat' => 1]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['1:0'],
                    building: new BuildingStateData(BuildingType::School, $player->id),
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: $targetTerrain,
                    terrain: $targetTerrain,
                    adjacentHexIds: ['0:0'],
                ),
            ]),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 3),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            availableCompetencyIds: [Competency::Competency10->value],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseCompetency,
                $player->id,
                [Competency::Competency10->value],
                ['reason' => 'building', 'builtHexId' => '0:0', 'buildingType' => BuildingType::School->value],
            ),
        )]);

        $this->actingAs($user)->post(route('games.starting-competency.store', $game), [
            'competency_id' => Competency::Competency10->value,
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(PendingInteractionType::PlaceNeutralBuilding, $game->state->pendingInteraction?->type);
        $this->assertSame(BuildingType::Tower->value, $game->state->pendingInteraction?->context['buildingType']);
        $this->assertSame(['1:0'], $game->state->pendingInteraction?->optionIds);

        $this->post(route('games.innovation.neutral-building', $game), ['hex_id' => '1:0'])
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $tower = $game->state->board->hexes[1];
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(3 - $expectedToolCost, $game->state->players[0]->resources->tools);
        $this->assertSame(TerrainType::Forest, $tower->terrain);
        $this->assertSame(BuildingType::Tower, $tower->building?->type);
        $this->assertTrue($tower->building?->isNeutral);
        $this->assertSame('1:0', $game->actions()->sole()->payload['neutral_building']['hex_id']);
        $this->assertSame(BuildingType::Tower->value, $game->actions()->sole()->payload['neutral_building']['type']);
    }

    public function test_player_chooses_an_available_palace_tile_after_building_a_palace(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::Guild, $player->id),
                ),
            ]),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 4, coins: 6),
            )],
            availablePalaceIds: [
                PalaceAbility::Palace01->value,
                PalaceAbility::Palace17->value,
            ],
        )]);

        $this->actingAs($user)->post(route('games.building-upgrade', $game), [
            'hex_id' => '0:0',
            'target' => BuildingType::Palace->value,
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(PendingInteractionType::ChoosePalace, $game->state->pendingInteraction?->type);
        $this->assertSame([
            'reason' => 'building',
            'builtHexId' => '0:0',
        ], $game->state->pendingInteraction?->context);
        $this->assertSame([
            PalaceAbility::Palace01->value,
            PalaceAbility::Palace17->value,
        ], $game->state->pendingInteraction?->optionIds);
        $this->assertNull($game->state->players[0]->palaceId);

        $this->post(route('games.palace-choice', $game), [
            'palace_id' => PalaceAbility::Palace02->value,
        ])->assertSessionHasErrors('palace_id');

        $this->post(route('games.palace-choice', $game), [
            'palace_id' => PalaceAbility::Palace17->value,
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(PalaceAbility::Palace17->value, $game->state->players[0]->palaceId);
        $this->assertSame(30, $game->state->players[0]->victoryPoints);
        $this->assertSame([PalaceAbility::Palace01->value], $game->state->availablePalaceIds);
        $this->assertNull($game->state->pendingInteraction);
        $this->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page->where(
                    'game.data.playerBoardStates.0.palaceId',
                    PalaceAbility::Palace17->value,
                ),
            );
        $this->assertSame([
            GameActionType::UpgradeBuilding,
            GameActionType::ChoosePalace,
        ], $game->actions()->orderBy('sequence')->pluck('type')->all());
    }

    public function test_palace_sixteen_places_a_free_guild_on_any_empty_homeland_hex(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::Guild, $player->id),
                ),
                new BoardHexStateData(
                    id: '5:5',
                    q: 5,
                    r: 5,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: TerrainType::Desert,
                    terrain: TerrainType::Desert,
                ),
            ]),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 4, coins: 6),
            )],
            availablePalaceIds: [PalaceAbility::Palace16->value],
        )]);

        $this->actingAs($user)->post(route('games.building-upgrade', $game), [
            'hex_id' => '0:0',
            'target' => BuildingType::Palace->value,
        ]);
        $this->post(route('games.palace-choice', $game), [
            'palace_id' => PalaceAbility::Palace16->value,
        ]);

        $game->refresh();
        $this->assertSame(PendingInteractionType::PlacePalaceGuild, $game->state->pendingInteraction?->type);
        $this->assertSame(['5:5'], $game->state->pendingInteraction?->optionIds);

        $this->post(route('games.palace-guild.store', $game), ['hex_id' => '1:0'])
            ->assertSessionHasErrors('hex_id');
        $this->post(route('games.palace-guild.store', $game), ['hex_id' => '5:5'])
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame('5:5', $game->state->pendingInteraction?->context['selectedHexId']);
        $this->assertSame(BuildingType::Guild, collect($game->state->board->hexes)->firstWhere('id', '5:5')?->building?->type);

        $this->delete(route('games.palace-guild.destroy', $game))
            ->assertRedirect(route('games.show', $game));
        $game->refresh();
        $this->assertNull(collect($game->state->board->hexes)->firstWhere('id', '5:5')?->building);
        $this->assertNull($game->state->pendingInteraction?->context['selectedHexId']);

        $this->post(route('games.palace-guild.store', $game), ['hex_id' => '5:5']);
        $this->post(route('games.palace-guild.confirm', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(BuildingType::Guild, collect($game->state->board->hexes)->firstWhere('id', '5:5')?->building?->type);
        $this->assertSame(GameActionType::PlacePalaceGuild, $game->actions()->latest('sequence')->firstOrFail()->type);
    }

    public function test_neutral_university_does_not_grant_a_competency(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 10,
            userId: 20,
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
                    building: new BuildingStateData(
                        BuildingType::University,
                        $playerState->playerId,
                        isNeutral: true,
                    ),
                ),
            ]),
            players: [$playerState],
            availableCompetencyIds: [Competency::Competency04->value],
        );

        $nextActiveUserId = app(CreateBuildingFollowUpInteractionAction::class)->execute(
            $state,
            $playerState,
            '0:0',
            BuildingType::University,
        );

        $this->assertNull($state->pendingInteraction);
        $this->assertSame($playerState->userId, $nextActiveUserId);
        $this->assertSame([], $playerState->competencyIds);
    }

    public function test_active_player_can_exchange_multiple_resources_without_ending_the_turn(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $game->update([
            'state' => new GameStateData(
                turnOrder: [$player->id],
                round: new RoundStateData(phase: GamePhase::Actions),
                players: [new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlThree: 10),
                    ),
                )],
            ),
        ]);

        $this->actingAs($user)
            ->post(route('games.resource-exchange', $game), [
                'exchanges' => $this->resourceExchanges(
                    powerToScholar: 1,
                    powerToBook: ['law' => 1],
                ),
            ])
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlThree);
        $this->assertSame(10, $game->state->players[0]->resources->power->bowlOne);
        $this->assertSame(1, $game->state->players[0]->resources->scholars);
        $this->assertSame(1, $game->state->players[0]->resources->books->law);
        $this->assertSame($user->id, $game->active_player_id);
        $this->assertSame(GameActionType::ExchangeResources, $game->actions()->sole()->type);
        $this->assertSame(1, $game->actions()->sole()->payload['exchanges']['power_to_book']['law']);
        $this->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page->where('game.data.canFinishCurrentTurn', false),
            );
        $this->post(route('games.current-turn.finish', $game))->assertForbidden();
    }

    public function test_scholars_advance_disciplines_and_only_placed_scholars_leave_the_pool(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $firstUser->id,
        ]);
        $firstPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $firstUser->id,
            'seat' => 1,
        ]);
        $secondPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $secondUser->id,
            'seat' => 2,
        ]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$firstPlayer->id, $secondPlayer->id],
            round: new RoundStateData(
                phase: GamePhase::Actions,
                scoringTileId: RoundScoringTile::KnowledgeMedicine->value,
            ),
            players: [
                new GamePlayerStateData(
                    playerId: $firstPlayer->id,
                    userId: $firstUser->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(scholars: 2),
                ),
                new GamePlayerStateData(
                    playerId: $secondPlayer->id,
                    userId: $secondUser->id,
                    color: PlayerColor::Red,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Mountain,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(scholars: 1),
                ),
            ],
        )]);

        $this->assertSame(7, $game->state->players[0]->scholarPoolSize);
        $this->assertSame(7, $game->state->players[1]->scholarPoolSize);
        $firstPlayerVictoryPoints = $game->state->players[0]->victoryPoints;
        $secondPlayerVictoryPoints = $game->state->players[1]->victoryPoints;

        $this->actingAs($firstUser)->post(route('games.scholar', $game), [
            'discipline' => 'law',
            'place' => true,
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(3, $game->state->players[0]->knowledge->law);
        $this->assertSame(1, $game->state->players[0]->resources->scholars);
        $this->assertSame(6, $game->state->players[0]->scholarPoolSize);
        $this->assertSame(['law'], $game->state->players[0]->scholarDisciplineIds);
        $this->assertSame($firstPlayerVictoryPoints + 3, $game->state->players[0]->victoryPoints);
        $this->assertSame(3, $game->actions()->latest('sequence')->firstOrFail()->payload['victory_points']);

        $state = $game->state;
        $state->round->hasTakenMainAction = false;
        $state->turnStartSnapshot = null;
        $state->round->turnStartVersion = null;
        $game->update(['active_player_id' => $secondUser->id, 'state' => $state]);

        $this->actingAs($secondUser)->post(route('games.scholar', $game), [
            'discipline' => 'law',
            'place' => true,
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(2, $game->state->players[1]->knowledge->law);
        $this->assertSame(0, $game->state->players[1]->resources->scholars);
        $this->assertSame(6, $game->state->players[1]->scholarPoolSize);
        $this->assertSame($secondPlayerVictoryPoints + 2, $game->state->players[1]->victoryPoints);

        $state = $game->state;
        $state->round->hasTakenMainAction = false;
        $state->turnStartSnapshot = null;
        $state->round->turnStartVersion = null;
        $game->update(['active_player_id' => $firstUser->id, 'state' => $state]);

        $this->actingAs($firstUser)->post(route('games.scholar', $game), [
            'discipline' => 'medicine',
            'place' => false,
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->knowledge->medicine);
        $this->assertSame(0, $game->state->players[0]->resources->scholars);
        $this->assertSame(6, $game->state->players[0]->scholarPoolSize);
        $this->assertSame(['law'], $game->state->players[0]->scholarDisciplineIds);
        $this->assertSame($firstPlayerVictoryPoints + 4, $game->state->players[0]->victoryPoints);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame([
            GameActionType::SendScholar,
            GameActionType::SendScholar,
            GameActionType::SendScholar,
        ], $game->actions()->orderBy('sequence')->pluck('type')->all());
    }

    public function test_player_can_confirm_an_innovation_purchase_with_books_and_palace_surcharge(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'innovation-purchase');
        $setupPool->innovations[0] = Innovation::LeagueOfCities;
        $playerState = new GamePlayerStateData(
            playerId: $player->id,
            userId: $user->id,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            townTileIds: [TownTile::Tools->value, TownTile::Coins->value],
            resources: new PlayerResourcesData(
                coins: 10,
                books: new BookSupplyData(banking: 2, law: 2, medicine: 1),
            ),
        );
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            players: [$playerState],
            round: new RoundStateData(phase: GamePhase::Actions),
            availableInventionIds: [Innovation::LeagueOfCities->value],
            setupPool: $setupPool,
        )]);

        $this->actingAs($user)->post(route('games.innovation', $game), [
            'innovation' => Innovation::LeagueOfCities->value,
            'book_counts' => ['banking' => 2, 'law' => 2, 'engineering' => 0, 'medicine' => 1],
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $updatedPlayerState = $game->state->players[0];
        $this->assertSame(5, $updatedPlayerState->resources->coins);
        $this->assertSame(0, $updatedPlayerState->resources->books->banking);
        $this->assertSame(0, $updatedPlayerState->resources->books->law);
        $this->assertSame(0, $updatedPlayerState->resources->books->medicine);
        $this->assertSame([Innovation::LeagueOfCities->value], $updatedPlayerState->inventionIds);
        $this->assertSame(30, $updatedPlayerState->victoryPoints);
        $this->assertSame([], $game->state->availableInventionIds);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame(GameActionType::MakeInnovation, $game->actions()->sole()->type);
        $this->assertSame(10, $game->actions()->sole()->payload['reward']['victoryPoints']);
    }

    public function test_innovation_purchase_is_unavailable_without_required_resources(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'unaffordable-innovation');
        $setupPool->innovations[0] = Innovation::LeagueOfCities;
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(coins: 10),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            availableInventionIds: [Innovation::LeagueOfCities->value],
            setupPool: $setupPool,
        )]);

        $this->actingAs($user)->get(route('games.show', $game))->assertInertia(
            fn (Assert $page) => $page
                ->where('game.data.canMakeInnovation', false)
                ->where('game.data.innovationStates.0.isAvailable', true)
                ->where('game.data.innovationStates.0.isAffordable', false),
        );
    }

    public function test_invalid_innovation_book_selection_does_not_change_game_state_or_history(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'innovation-rollback');
        $setupPool->innovations[0] = Innovation::Professor;
        $playerState = new GamePlayerStateData(
            playerId: $player->id,
            userId: $user->id,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(
                coins: 10,
                books: new BookSupplyData(engineering: 3, medicine: 2),
            ),
        );
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            players: [$playerState],
            round: new RoundStateData(phase: GamePhase::Actions),
            availableInventionIds: [Innovation::Professor->value],
            setupPool: $setupPool,
        )]);
        $this->actingAs($user)->post(route('games.innovation', $game), [
            'innovation' => Innovation::Professor->value,
            'book_counts' => ['banking' => 0, 'law' => 0, 'engineering' => 3, 'medicine' => 2],
        ])->assertSessionHasErrors('book_counts');

        $game->refresh();
        $this->assertSame(10, $game->state->players[0]->resources->coins);
        $this->assertSame(3, $game->state->players[0]->resources->books->engineering);
        $this->assertSame(2, $game->state->players[0]->resources->books->medicine);
        $this->assertSame([], $game->state->players[0]->inventionIds);
        $this->assertSame([Innovation::Professor->value], $game->state->availableInventionIds);
        $this->assertFalse($game->state->round->hasTakenMainAction);
        $this->assertSame(0, $game->actions()->count());
    }

    #[DataProvider('neutralInnovationBuildingTerrainProvider')]
    public function test_player_builds_a_neutral_innovation_building_after_any_required_terraforming(
        TerrainType $targetTerrain,
        int $expectedToolCost,
    ): void {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'neutral-innovation-building');
        $setupPool->innovations[0] = Innovation::Workshop;
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['1:0'],
                    building: new BuildingStateData(BuildingType::Workshop, $player->id),
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: $targetTerrain,
                    terrain: $targetTerrain,
                    adjacentHexIds: ['0:0'],
                ),
            ]),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(
                    tools: 3,
                    coins: 10,
                    books: new BookSupplyData(banking: 2, law: 2, medicine: 1),
                ),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            availableInventionIds: [Innovation::Workshop->value],
            setupPool: $setupPool,
        )]);

        $this->actingAs($user)->post(route('games.innovation', $game), [
            'innovation' => Innovation::Workshop->value,
            'book_counts' => ['banking' => 2, 'law' => 2, 'engineering' => 0, 'medicine' => 1],
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(PendingInteractionType::PlaceNeutralBuilding, $game->state->pendingInteraction?->type);
        $this->assertSame(['1:0'], $game->state->pendingInteraction?->optionIds);

        $this->post(route('games.innovation.neutral-building', $game), ['hex_id' => '1:0'])
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(3 - $expectedToolCost, $game->state->players[0]->resources->tools);
        $this->assertSame(TerrainType::Forest, $game->state->board->hexes[1]->terrain);
        $this->assertSame(BuildingType::Workshop, $game->state->board->hexes[1]->building?->type);
        $this->assertTrue($game->state->board->hexes[1]->building?->isNeutral);
        $this->assertSame('1:0', $game->actions()->sole()->payload['neutral_building']['hex_id']);
        $this->assertSame($expectedToolCost, $game->actions()->sole()->payload['neutral_building']['tools']);
    }

    /** @return array<string, array{TerrainType, int}> */
    public static function neutralInnovationBuildingTerrainProvider(): array
    {
        return [
            'without terraforming' => [TerrainType::Forest, 0],
            'with terraforming' => [TerrainType::Mountain, 3],
        ];
    }

    public function test_player_distributes_books_received_from_an_innovation_in_the_same_history_action(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'innovation-reward-books');
        $setupPool->innovations[0] = Innovation::SteamEngine;
        $playerState = new GamePlayerStateData(
            playerId: $player->id,
            userId: $user->id,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(
                coins: 10,
                books: new BookSupplyData(banking: 2, law: 2, medicine: 1),
            ),
        );
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            players: [$playerState],
            round: new RoundStateData(phase: GamePhase::Actions),
            availableInventionIds: [Innovation::SteamEngine->value],
            setupPool: $setupPool,
        )]);

        $this->actingAs($user)->post(route('games.innovation', $game), [
            'innovation' => Innovation::SteamEngine->value,
            'book_counts' => ['banking' => 2, 'law' => 2, 'engineering' => 0, 'medicine' => 1],
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(PendingInteractionType::ChooseInnovationBooks, $game->state->pendingInteraction?->type);
        $this->assertSame(2, $game->state->pendingInteraction?->context['bookCount']);
        $this->assertSame('development_tracks', $game->state->pendingInteraction?->context['source']);
        $this->assertSame(2, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(1, $game->actions()->count());

        $this->actingAs($user)->post(route('games.books', $game), [
            'book_counts' => ['banking' => 1, 'law' => 0, 'engineering' => 0, 'medicine' => 0],
        ])->assertSessionHasErrors('book_counts');

        $game->refresh();
        $this->assertSame(PendingInteractionType::ChooseInnovationBooks, $game->state->pendingInteraction?->type);
        $this->assertSame(2, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(1, $game->actions()->count());

        $this->actingAs($user)->post(route('games.books', $game), [
            'book_counts' => ['banking' => 1, 'law' => 0, 'engineering' => 1, 'medicine' => 0],
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(1, $game->state->players[0]->resources->books->banking);
        $this->assertSame(1, $game->state->players[0]->resources->books->engineering);
        $this->assertSame(0, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(1, $game->actions()->count());
        $this->assertEquals(
            ['banking' => 1, 'law' => 0, 'engineering' => 1, 'medicine' => 0],
            $game->actions()->sole()->payload['reward_book_counts'],
        );
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
                building: new BuildingStateData($type, 15),
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

    public function test_shipping_advancement_grants_rewards_for_each_reached_level(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
        );
        $advanceDevelopmentTrack = app(AdvanceDevelopmentTrackAction::class);

        $advanceDevelopmentTrack->advanceShipping($playerState);
        $this->assertSame(1, $playerState->shippingLevel);
        $this->assertSame(22, $playerState->victoryPoints);
        $this->assertSame(0, $playerState->resources->books->unassigned);

        $advanceDevelopmentTrack->advanceShipping($playerState);
        $this->assertSame(2, $playerState->shippingLevel);
        $this->assertSame(22, $playerState->victoryPoints);
        $this->assertSame(2, $playerState->resources->books->unassigned);

        $advanceDevelopmentTrack->advanceShipping($playerState);
        $this->assertSame(3, $playerState->shippingLevel);
        $this->assertSame(26, $playerState->victoryPoints);
        $this->assertSame(2, $playerState->resources->books->unassigned);
    }

    public function test_player_can_confirm_shipping_advancement_and_restart_the_turn(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            round: new RoundStateData(
                phase: GamePhase::Actions,
                scoringTileId: RoundScoringTile::TrackEngineering->value,
            ),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(coins: 4, scholars: 1),
            )],
        )]);

        $this->actingAs($user)->post(route('games.shipping', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->shippingLevel);
        $this->assertSame(0, $game->state->players[0]->resources->coins);
        $this->assertSame(0, $game->state->players[0]->resources->scholars);
        $this->assertSame(25, $game->state->players[0]->victoryPoints);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame(GameActionType::AdvanceShipping, $game->actions()->sole()->type);

        $this->post(route('games.current-turn.restart', $game));
        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->shippingLevel);
        $this->assertSame(4, $game->state->players[0]->resources->coins);
        $this->assertSame(1, $game->state->players[0]->resources->scholars);

        $state = $game->state;
        $state->players[0]->shippingLevel = 1;
        $game->update(['state' => $state]);

        $this->post(route('games.shipping', $game));
        $game->refresh();
        $this->assertSame(2, $game->state->players[0]->shippingLevel);
        $this->assertSame(PendingInteractionType::ChooseInnovationBooks, $game->state->pendingInteraction?->type);
        $this->assertSame('shipping', $game->state->pendingInteraction?->context['source']);

        $this->post(route('games.books', $game), [
            'book_counts' => [
                'banking' => 0,
                'law' => 2,
                'engineering' => 0,
                'medicine' => 0,
            ],
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(2, $game->state->players[0]->resources->books->law);
        $this->assertSame(23, $game->state->players[0]->victoryPoints);
        $this->assertSame(
            2,
            $game->actions()->sole()->payload['reward_book_counts']['law'],
        );
    }

    public function test_terraforming_advancement_grants_rewards_for_each_reached_level(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
        );
        $advanceDevelopmentTrack = app(AdvanceDevelopmentTrackAction::class);

        $advanceDevelopmentTrack->advanceTerraforming($playerState);
        $this->assertSame(1, $playerState->terraformingLevel);
        $this->assertSame(2, $playerState->resources->books->unassigned);
        $this->assertSame(20, $playerState->victoryPoints);

        $advanceDevelopmentTrack->advanceTerraforming($playerState);
        $this->assertSame(2, $playerState->terraformingLevel);
        $this->assertSame(2, $playerState->resources->books->unassigned);
        $this->assertSame(26, $playerState->victoryPoints);
    }

    public function test_player_can_confirm_terraforming_advancement_choose_books_and_restart_the_turn(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            round: new RoundStateData(
                phase: GamePhase::Actions,
                scoringTileId: RoundScoringTile::TrackEngineering->value,
            ),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 2, coins: 10, scholars: 2),
            )],
        )]);

        $this->actingAs($user)->post(route('games.terraforming', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->terraformingLevel);
        $this->assertSame(1, $game->state->players[0]->resources->tools);
        $this->assertSame(5, $game->state->players[0]->resources->coins);
        $this->assertSame(1, $game->state->players[0]->resources->scholars);
        $this->assertSame(2, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(23, $game->state->players[0]->victoryPoints);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame(PendingInteractionType::ChooseInnovationBooks, $game->state->pendingInteraction?->type);
        $this->assertSame('terraforming', $game->state->pendingInteraction?->context['source']);
        $this->assertSame(GameActionType::AdvanceTerraforming, $game->actions()->sole()->type);

        $this->post(route('games.books', $game), [
            'book_counts' => [
                'banking' => 1,
                'law' => 0,
                'engineering' => 1,
                'medicine' => 0,
            ],
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(1, $game->state->players[0]->resources->books->banking);
        $this->assertSame(1, $game->state->players[0]->resources->books->engineering);
        $this->assertSame(1, $game->actions()->sole()->payload['reward_book_counts']['banking']);

        $this->post(route('games.current-turn.restart', $game));
        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->terraformingLevel);
        $this->assertSame(2, $game->state->players[0]->resources->tools);
        $this->assertSame(10, $game->state->players[0]->resources->coins);
        $this->assertSame(2, $game->state->players[0]->resources->scholars);
        $this->assertSame(0, $game->state->players[0]->resources->books->banking);

        $state = $game->state;
        $state->players[0]->terraformingLevel = 1;
        $game->update(['state' => $state]);

        $this->post(route('games.terraforming', $game));
        $game->refresh();
        $this->assertSame(2, $game->state->players[0]->terraformingLevel);
        $this->assertSame(1, $game->state->players[0]->resources->tools);
        $this->assertSame(5, $game->state->players[0]->resources->coins);
        $this->assertSame(29, $game->state->players[0]->victoryPoints);
        $this->assertNull($game->state->pendingInteraction);
    }

    public function test_player_cannot_advance_terraforming_without_resources_or_past_the_last_level(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 1, coins: 4, scholars: 1),
            )],
        )]);

        $this->actingAs($user)->post(route('games.terraforming', $game))
            ->assertSessionHasErrors('terraforming');

        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->terraformingLevel);
        $this->assertSame(1, $game->state->players[0]->resources->tools);
        $this->assertSame(4, $game->state->players[0]->resources->coins);
        $this->assertSame(0, $game->actions()->count());

        $state = $game->state;
        $state->players[0]->terraformingLevel = 2;
        $state->players[0]->resources->tools = 1;
        $state->players[0]->resources->coins = 5;
        $state->players[0]->resources->scholars = 1;
        $game->update(['state' => $state]);

        $this->post(route('games.terraforming', $game))
            ->assertSessionHasErrors('terraforming');

        $game->refresh();
        $this->assertSame(2, $game->state->players[0]->terraformingLevel);
        $this->assertSame(1, $game->state->players[0]->resources->tools);
        $this->assertSame(5, $game->state->players[0]->resources->coins);
        $this->assertSame(1, $game->state->players[0]->resources->scholars);
        $this->assertSame(0, $game->actions()->count());
    }

    public function test_brown_player_pays_discounted_terraforming_advancement_cost(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Brown,
                faction: Faction::Blessed,
                homeland: TerrainType::Plains,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 1, coins: 1, scholars: 1),
                terraformingLevel: 1,
            )],
        )]);

        $this->actingAs($user)->post(route('games.terraforming', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(2, $game->state->players[0]->terraformingLevel);
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertSame(0, $game->state->players[0]->resources->coins);
        $this->assertSame(0, $game->state->players[0]->resources->scholars);
        $this->assertSame(1, $game->actions()->sole()->payload['coins']);
        $this->assertSame(1, $game->actions()->sole()->payload['tools']);
    }

    public function test_all_resource_exchange_rates_are_applied(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 1,
            userId: 1,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(
                tools: 2,
                scholars: 2,
                power: new PowerBowlsStateData(bowlThree: 15),
            ),
        );
        $playerState->resources->books->law = 1;
        $applyResourceExchange = app(ApplyResourceExchangeAction::class);

        $applyResourceExchange->execute($playerState, $this->resourceExchanges(
            powerToScholar: 1,
            powerToTool: 1,
            powerToCoin: 1,
            scholarToTool: 1,
            toolToCoin: 1,
            powerToBook: ['law' => 1],
            bookToCoin: ['law' => 1],
        ));

        $this->assertSame(1, $playerState->resources->power->bowlThree);
        $this->assertSame(14, $playerState->resources->power->bowlOne);
        $this->assertSame(2, $playerState->resources->scholars);
        $this->assertSame(3, $playerState->resources->tools);
        $this->assertSame(3, $playerState->resources->coins);
        $this->assertSame(1, $playerState->resources->books->law);
    }

    public function test_resource_exchange_batch_is_not_partially_applied(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $game->update([
            'state' => new GameStateData(
                turnOrder: [$player->id],
                round: new RoundStateData(phase: GamePhase::Actions),
                players: [new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlThree: 5),
                    ),
                )],
            ),
        ]);

        $this->actingAs($user)
            ->post(route('games.resource-exchange', $game), [
                'exchanges' => $this->resourceExchanges(powerToScholar: 1, powerToTool: 1),
            ])
            ->assertSessionHasErrors('exchanges');

        $game->refresh();
        $this->assertSame(5, $game->state->players[0]->resources->power->bowlThree);
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlOne);
        $this->assertSame(0, $game->state->players[0]->resources->scholars);
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertCount(0, $game->actions);
    }

    /** @return array{Game, User} */
    private function gameForPalaceAction(PalaceAbility $palace, ?BuildingType $building = null): array
    {
        $user = User::factory()->create();
        $game = Game::factory()->create(['status' => GameStatus::Active, 'phase' => GamePhase::Actions, 'active_player_id' => $user->id]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id, 'seat' => 1]);
        $hexes = $building === null ? [] : [new BoardHexStateData(
            id: '0:0',
            q: 0,
            r: 0,
            initialTerrain: TerrainType::Forest,
            terrain: TerrainType::Forest,
            building: new BuildingStateData($building, $player->id),
        )];
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: $hexes),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                palaceId: $palace->value,
            )],
        )]);

        return [$game, $user];
    }

    /** @return array{Game, User, User} */
    private function gameForPassing(): array
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $firstUser->id,
        ]);
        $firstPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $firstUser->id,
            'seat' => 1,
        ]);
        $secondPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $secondUser->id,
            'seat' => 2,
        ]);
        $setupPool = (new GameSetupPoolFactory())->createFromSeed(2, 'pass-test');
        $setupPool->roundScoringTiles[0] = RoundScoringTile::WorkshopLaw;
        $setupPool->availableRoundBonuses = [
            new RoundBonusOfferData(RoundBonus::RiverWorkshop, 2),
            new RoundBonusOfferData(RoundBonus::BuildGuild),
            new RoundBonusOfferData(RoundBonus::Coins),
        ];
        $game->update(['state' => new GameStateData(
            turnOrder: [$firstPlayer->id, $secondPlayer->id],
            round: new RoundStateData(
                phase: GamePhase::Actions,
                scoringTileId: $setupPool->roundScoringTiles[0]->value,
            ),
            players: [
                new GamePlayerStateData(
                    playerId: $firstPlayer->id,
                    userId: $firstUser->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Knowledge,
                ),
                new GamePlayerStateData(
                    playerId: $secondPlayer->id,
                    userId: $secondUser->id,
                    color: PlayerColor::Blue,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Swamp,
                    roundBonus: RoundBonus::PowerCoins,
                ),
            ],
            setupPool: $setupPool,
        )]);

        return [$game, $firstUser, $secondUser];
    }

    /** @return array{Game, User} */
    private function gameForBridgeAction(RoundBonus $roundBonus = RoundBonus::Coins): array
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $game->update([
            'state' => new GameStateData(
                turnOrder: [$player->id],
                board: new BoardStateData(hexes: [
                    new BoardHexStateData(
                        id: '8:5',
                        q: 8,
                        r: 5,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                        riverConnectedHexIds: ['6:7', '8:7'],
                        building: new BuildingStateData(BuildingType::Workshop, $player->id),
                    ),
                    new BoardHexStateData(
                        id: '8:6',
                        q: 8,
                        r: 6,
                        initialTerrain: TerrainType::Water,
                        terrain: TerrainType::Water,
                    ),
                    new BoardHexStateData(
                        id: '7:6',
                        q: 7,
                        r: 6,
                        initialTerrain: TerrainType::Water,
                        terrain: TerrainType::Water,
                    ),
                    new BoardHexStateData(
                        id: '7:7',
                        q: 7,
                        r: 7,
                        initialTerrain: TerrainType::Plains,
                        terrain: TerrainType::Plains,
                        riverConnectedHexIds: ['8:5'],
                    ),
                ], riverBankHexIds: ['8:5', '7:7']),
                round: new RoundStateData(phase: GamePhase::Actions),
                players: [new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: $roundBonus,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlThree: 3),
                    ),
                )],
            ),
        ]);

        return [$game, $user];
    }

    /** @return array{Game, User} */
    private function gameForFactionAction(Faction $faction): array
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $game->update([
            'state' => new GameStateData(
                turnOrder: [$player->id],
                round: new RoundStateData(phase: GamePhase::Actions),
                players: [new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: $faction,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlOne: 5),
                    ),
                )],
            ),
        ]);

        return [$game, $user];
    }

    /**
     * @param array<string, int> $powerToBook
     * @param array<string, int> $bookToCoin
     * @return array<string, int|array<string, int>>
     */
    private function resourceExchanges(
        int $powerToScholar = 0,
        int $powerToTool = 0,
        int $powerToCoin = 0,
        int $scholarToTool = 0,
        int $toolToCoin = 0,
        array $powerToBook = [],
        array $bookToCoin = [],
    ): array {
        $emptyBooks = ['banking' => 0, 'law' => 0, 'engineering' => 0, 'medicine' => 0];

        return [
            'power_to_scholar' => $powerToScholar,
            'power_to_tool' => $powerToTool,
            'power_to_coin' => $powerToCoin,
            'scholar_to_tool' => $scholarToTool,
            'tool_to_coin' => $toolToCoin,
            'power_to_book' => array_replace($emptyBooks, $powerToBook),
            'book_to_coin' => array_replace($emptyBooks, $bookToCoin),
        ];
    }

    public function test_game_history_is_loaded_in_batches_of_twenty_five(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create();

        foreach (range(1, 30) as $sequence) {
            GameAction::factory()->create([
                'game_id' => $game->id,
                'player_id' => $user->id,
                'sequence' => $sequence,
                'state_version_before' => $sequence - 1,
                'state_version_after' => $sequence,
            ]);
        }

        $this->actingAs($user)
            ->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->has('game.data.history.data', 25)
                    ->where('game.data.history.hasMore', true)
                    ->where('game.data.history.data.0.sequence', 30)
                    ->where('game.data.history.data.24.sequence', 6),
            );

        $this->getJson(route('games.history', ['game' => $game, 'before_sequence' => 6]))
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.sequence', 5)
            ->assertJsonPath('data.4.sequence', 1)
            ->assertJsonPath('hasMore', false);
    }

    public function test_guest_cannot_view_or_create_games(): void
    {
        $this->get(route('games.index'))->assertRedirect(route('login'));
        $this->post(route('games.store'))->assertRedirect(route('login'));
    }

    public function test_user_sees_open_lobbies_and_their_own_games(): void
    {
        $user = User::factory()->create();
        $openGame = Game::factory()->create([
            'state' => new GameStateData(
                board: (new BoardStateFactory())->create(MapVariant::OneToThreePlayers),
            ),
        ]);
        $ownGame = Game::factory()->create();
        Game::factory()->active()->create();

        GamePlayer::factory()->create([
            'game_id' => $ownGame->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('games.index'))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                ->component('games/Index')
                ->has('games.data', 2)
                ->where('games.data.0.id', $ownGame->id)
                ->where('games.data.0.status', 'lobby')
                ->where('games.data.0.currentRound', null)
                ->where('games.data.0.mapVariant', MapVariant::ThreeToFivePlayers->value)
                ->where('games.data.0.playersCount', 1)
                ->where('games.data.0.isJoined', true)
                ->has('games.data.0.createdAt')
                ->missing('games.data.0.board')
                ->missing('games.data.0.players')
                ->missing('games.data.0.playerBoardStates')
                ->where('games.data.1.id', $openGame->id)
                ->where('games.data.1.currentRound', null)
                ->where('games.data.1.mapVariant', MapVariant::OneToThreePlayers->value)
                ->where('games.data.1.isJoined', false)
                ->missing('games.data.2')
            );
    }

    public function test_game_list_contains_the_current_round_for_an_active_game(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->active()->create(['round' => 3]);
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('games.index'))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->has('games.data', 1)
                    ->where('games.data.0.id', $game->id)
                    ->where('games.data.0.status', GameStatus::Active->value)
                    ->where('games.data.0.currentRound', 3)
                    ->missing('games.data.0.board'),
            );
    }

    public function test_user_can_open_game_preparation_page_and_join(): void
    {
        $owner = User::factory()->create();
        $joiningUser = User::factory()->create();
        $game = Game::factory()->create();

        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);

        $this->actingAs($joiningUser)
            ->get(route('games.show', $game))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('games/Show')
                    ->where('game.data.id', $game->id)
                    ->where('game.data.board.variant', MapVariant::ThreeToFivePlayers->value)
                    ->has('game.data.board.hexes')
                    ->where('game.data.isJoined', false)
                ->where('game.data.playersCount', 1)
                ->where('game.data.players.0.user.name', $owner->name)
            );

        $this->post(route('games.players.store', $game))
            ->assertRedirect(route('games.show', $game));

        $this->assertTrue($game->players()->whereBelongsTo($joiningUser)->where('seat', 2)->exists());
    }

    public function test_user_cannot_join_the_same_game_twice(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create();

        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);

        $this->actingAs($user)
            ->post(route('games.players.store', $game))
            ->assertSessionHasErrors('game');

        $this->assertSame(1, $game->players()->count());
    }

    public function test_user_cannot_join_a_full_game(): void
    {
        $game = Game::factory()->create([
            'state' => new GameStateData(
                board: (new BoardStateFactory())->create(MapVariant::OneToThreePlayers),
            ),
        ]);

        foreach (range(1, 3) as $seat) {
            GamePlayer::factory()->create([
                'game_id' => $game->id,
                'seat' => $seat,
            ]);
        }

        $this->actingAs(User::factory()->create())
            ->post(route('games.players.store', $game))
            ->assertSessionHasErrors('game');

        $this->assertSame(3, $game->players()->count());
    }

    public function test_player_can_confirm_and_cancel_readiness(): void
    {
        $user = User::factory()->create();
        $gamePlayer = GamePlayer::factory()->create([
            'user_id' => $user->id,
            'is_ready' => false,
        ]);

        $this->actingAs($user)
            ->patch(route('games.players.readiness.update', [$gamePlayer->game, $gamePlayer]), [
                'is_ready' => true,
            ])
            ->assertRedirect(route('games.show', $gamePlayer->game));

        $this->assertTrue($gamePlayer->refresh()->is_ready);

        $this->patch(route('games.players.readiness.update', [$gamePlayer->game, $gamePlayer]), [
            'is_ready' => false,
        ])->assertRedirect(route('games.show', $gamePlayer->game));

        $this->assertFalse($gamePlayer->refresh()->is_ready);
    }

    public function test_player_cannot_change_another_players_readiness(): void
    {
        $gamePlayer = GamePlayer::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patch(route('games.players.readiness.update', [$gamePlayer->game, $gamePlayer]), [
                'is_ready' => true,
            ])
            ->assertForbidden();

        $this->assertFalse($gamePlayer->refresh()->is_ready);
    }

    public function test_user_can_create_a_game_and_becomes_its_first_player(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('games.store'), [
                'map_variant' => MapVariant::OneToThreePlayers->value,
            ])
            ->assertRedirect(route('games.index'));

        $game = Game::query()->sole();

        $this->assertSame(MapVariant::OneToThreePlayers, $game->state->board->variant);
        $this->assertTrue($game->players()->whereBelongsTo($user)->where('seat', 1)->exists());
    }

    public function test_map_variant_is_required_and_must_be_valid(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('games.store'), ['map_variant' => 'unknown'])
            ->assertSessionHasErrors('map_variant');

        $this->assertSame(0, Game::query()->count());
    }

    public function test_owner_can_start_game_when_all_players_are_ready(): void
    {
        $owner = User::factory()->create();
        $secondUser = User::factory()->create();
        $game = Game::factory()->create(['random_seed' => 'repeatable-game-seed']);
        $ownerPlayer = GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        $secondPlayer = GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $secondUser->id,
            'seat' => 2,
        ]);

        $this->actingAs($owner)
            ->post(route('games.start', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();

        $this->assertSame(GameStatus::Active, $game->status);
        $this->assertSame(GamePhase::Setup, $game->phase);
        $this->assertNotNull($game->started_at);
        $this->assertSame(1, $game->version);
        $this->assertNotNull($game->state->setupPool);
        $this->assertSame(2, $game->state->setupPool->playerCount);
        $this->assertSame($game->state->board->variant, $game->state->setupPool->mapVariant);
        $this->assertIsString($game->state->setupPool->roundScoringTiles[0]);
        $this->assertIsString($game->state->setupPool->bookActions[0]);
        $this->assertSame(
            $game->state->setupPool->roundScoringTiles[0],
            $game->state->round->scoringTileId,
        );
        $this->assertCount(21, $game->state->availableTownTileIds);
        $this->assertCount(4, $game->state->availablePalaceIds);
        $this->assertCount(6, $game->state->availableInventionIds);
        $this->assertCount(48, $game->state->availableCompetencyIds);
        $this->assertSame(
            4,
            array_count_values($game->state->availableCompetencyIds)[Competency::Competency01->value],
        );
        $this->assertCount(10, $game->state->roundBonusIds);
        $this->assertEqualsCanonicalizing(
            [$ownerPlayer->id, $secondPlayer->id],
            $game->state->turnOrder,
        );
        $this->assertContains($game->active_player_id, [$owner->id, $secondUser->id]);

        $startAction = $game->actions()->where('type', GameActionType::StartGame)->sole();
        $this->assertSame(GameActionType::StartGame, $startAction->type);
        $this->assertSame(1, $startAction->sequence);
        $this->assertSame(0, $startAction->state_version_before);
        $this->assertSame(1, $startAction->state_version_after);
        $this->assertSame('game_started', $startAction->events[0]['type']);
        $this->assertSame($game->random_seed, $startAction->events[0]['random_seed']);

        $this->actingAs($owner)
            ->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('game.data.turnOrder', $game->state->turnOrder)
                    ->where('game.data.activePlayerId', $game->active_player_id)
                    ->has('game.data.history.data', 2)
                    ->where('game.data.history.hasMore', false)
                    ->where('game.data.history.data.0.sequence', 2)
                    ->where('game.data.history.data.0.type', GameActionType::PhaseCheckpoint->value)
                    ->where('game.data.history.data.0.player', null)
                    ->where('game.data.history.data.1.type', GameActionType::StartGame->value)
                    ->where(
                        'game.data.availablePalaceIds',
                        $game->state->availablePalaceIds,
                    )
                    ->where(
                        'game.data.availableTownTileIds',
                        $game->state->availableTownTileIds,
                    )
                    ->has('game.data.roundBonusOffers', 3)
                    ->where(
                        'game.data.roundBonusOffers.0.roundBonus',
                        $game->state->setupPool
                            ->availableRoundBonuses[0]
                            ->roundBonus
                            ->value,
                    )
                    ->where(
                        'game.data.roundBonusOffers.0.coins',
                        $game->state->setupPool
                            ->availableRoundBonuses[0]
                            ->coins,
                    ),
            );
    }

    public function test_only_owner_can_start_game(): void
    {
        $owner = User::factory()->create();
        $secondUser = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $secondUser->id,
            'seat' => 2,
        ]);

        $this->actingAs($secondUser)
            ->post(route('games.start', $game))
            ->assertForbidden();

        $this->assertSame(GameStatus::Lobby, $game->refresh()->status);
        $this->assertNull($game->state->setupPool);
    }

    public function test_owner_can_undo_the_latest_action_and_remove_it_from_history(): void
    {
        $owner = User::factory()->create();
        $secondUser = User::factory()->create();
        $game = Game::factory()->create(['random_seed' => 'undo-game-seed']);
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $secondUser->id,
            'seat' => 2,
        ]);

        $this->actingAs($owner)->post(route('games.start', $game));

        $game->refresh();
        $this->assertSame(GameStatus::Active, $game->status);
        $this->assertSame(GameActionType::StartGame, $game->actions()->where('type', GameActionType::StartGame)->sole()->type);
        $this->assertSame(GameActionType::PhaseCheckpoint, $game->actions()->latest('sequence')->first()?->type);
        $activePlayerId = $game->active_player_id;
        $activeUser = User::query()->findOrFail($activePlayerId);
        $selectedBundle = $game->state->setupPool->planningBundles[0];

        $this->actingAs($activeUser)->post(route('games.planning-bundle.store', $game), [
            'homeland' => $selectedBundle->homeland->value,
        ]);

        $game->refresh();
        $this->assertSame(3, $game->actions()->count());

        $this->actingAs($secondUser)
            ->delete(route('games.history.latest.destroy', $game))
            ->assertForbidden();

        $this->actingAs($owner)
            ->delete(route('games.history.latest.destroy', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(GameStatus::Active, $game->status);
        $this->assertSame(GamePhase::Setup, $game->phase);
        $this->assertSame(1, $game->version);
        $this->assertSame($activePlayerId, $game->active_player_id);
        $this->assertCount(0, $game->state->planningSelections);
        $this->assertSame(2, $game->actions()->count());
        $this->assertTrue($game->players()->whereNull('faction')->whereNull('homeland')->exists());

        $this->delete(route('games.history.latest.destroy', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(GameStatus::Lobby, $game->status);
        $this->assertSame(GamePhase::Setup, $game->phase);
        $this->assertSame(0, $game->version);
        $this->assertNull($game->active_player_id);
        $this->assertNull($game->started_at);
        $this->assertNull($game->state->setupPool);
        $this->assertSame(0, $game->actions()->count());
    }

    public function test_game_cannot_start_until_all_players_are_ready(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'seat' => 2,
            'is_ready' => false,
        ]);

        $this->actingAs($owner)
            ->post(route('games.start', $game))
            ->assertSessionHasErrors('game');

        $this->assertSame(GameStatus::Lobby, $game->refresh()->status);
    }

    public function test_game_requires_at_least_two_players_to_start(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);

        $this->actingAs($owner)
            ->post(route('games.start', $game))
            ->assertSessionHasErrors('game');

        $this->assertSame(GameStatus::Lobby, $game->refresh()->status);
    }

    public function test_active_player_can_choose_planning_bundle(): void
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create(['random_seed' => 'planning-selection-seed']);

        foreach ($users as $index => $user) {
            GamePlayer::factory()->ready()->create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'seat' => $index + 1,
            ]);
        }

        $this->actingAs($users[0])->post(route('games.start', $game));
        $game->refresh();

        $activeUser = $users->firstWhere('id', $game->active_player_id);
        $bundle = collect($game->state->setupPool->planningBundles)->first(
            static fn (PlanningBundleData $bundle): bool => $bundle->homeland !== TerrainType::Wasteland
                && $bundle->faction !== Faction::Lizards,
        );

        $this->assertInstanceOf(User::class, $activeUser);
        $this->assertInstanceOf(PlanningBundleData::class, $bundle);

        $this->actingAs($activeUser)
            ->post(route('games.planning-bundle.store', $game), [
                'homeland' => TerrainType::Water->value,
            ])
            ->assertSessionHasErrors('homeland');

        $this->actingAs($activeUser)
            ->post(route('games.planning-bundle.store', $game), [
                'homeland' => $bundle->homeland->value,
            ])
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $player = $game->players()->whereBelongsTo($activeUser)->sole();

        $this->assertSame($bundle->homeland, $player->homeland);
        $this->assertSame($bundle->faction, $player->faction);
        $this->assertNotNull($player->color);
        $this->assertCount(7, $game->state->setupPool->planningBundles);
        $this->assertCount(1, $game->state->planningSelections);
        $this->assertSame($player->id, $game->state->planningSelections[0]->playerId);
        $this->assertCount(1, $game->state->players);
        $this->assertSame($player->id, $game->state->players[0]->playerId);
        $this->assertSame($bundle->roundBonus, $game->state->players[0]->roundBonus);
        $this->assertSame(15, $game->state->players[0]->resources->coins);
        $this->assertSame(12, $game->state->players[0]->resources->power->bowlOne
            + $game->state->players[0]->resources->power->bowlTwo
            + $game->state->players[0]->resources->power->bowlThree);
        $this->assertNotSame($activeUser->id, $game->active_player_id);

        $nextActiveUser = $users->firstWhere('id', $game->active_player_id);
        $this->assertInstanceOf(User::class, $nextActiveUser);

        $this->actingAs($nextActiveUser)
            ->post(route('games.planning-bundle.store', $game), [
                'homeland' => $bundle->homeland->value,
            ])
            ->assertSessionHasErrors('homeland');

        $game->refresh();
        $this->assertCount(7, $game->state->setupPool->planningBundles);
        $this->assertCount(1, $game->state->planningSelections);

        $this->get(route('games.show', $game))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('game.data.planningSelections.0.playerId', $player->id)
                    ->where(
                        'game.data.planningSelections.0.bundle.homeland',
                        $bundle->homeland->value,
                    )
                    ->where(
                        'game.data.planningSelections.0.bundle.faction',
                        $bundle->faction->value,
                    )
                    ->where(
                        'game.data.planningSelections.0.bundle.roundBonus',
                        $bundle->roundBonus->value,
                    )
                    ->where(
                        'game.data.players.'.($player->seat - 1).'.color',
                        $player->color->value,
                    )
                    ->has('game.data.planningBundles', 7)
                    ->has('game.data.planningBundleDescriptions.homelands', 8)
                    ->has('game.data.planningBundleDescriptions.factions', 12)
                    ->has('game.data.planningBundleDescriptions.roundBonuses', 10)
                    ->has('game.data.competencyDescriptions', 12)
                    ->has('game.data.innovationDescriptions', 18)
                    ->has('game.data.roundBonusDescriptions', 10)
                    ->has('game.data.palaceDescriptions', 17)
                    ->has('game.data.knowledgeDisciplineNames', 4)
                    ->where(
                        'game.data.competencyDescriptions.'.Competency::Competency01->value,
                        Competency::Competency01->description(),
                    )
                    ->where(
                        'game.data.innovationDescriptions.'.Innovation::DeusExMachina->value,
                        Innovation::DeusExMachina->description(),
                    )
                    ->where(
                        'game.data.roundBonusDescriptions.'.RoundBonus::Coins->value,
                        RoundBonus::Coins->description(),
                    )
                    ->where(
                        'game.data.palaceDescriptions.'.PalaceAbility::Palace01->value,
                        PalaceAbility::Palace01->description(),
                    )
                    ->where(
                        'game.data.knowledgeDisciplineNames.'.KnowledgeDiscipline::Banking->value,
                        KnowledgeDiscipline::Banking->displayName(),
                    )
                    ->where(
                        'game.data.planningBundleDescriptions.homelands.desert',
                        TerrainType::Desert->description(),
                    )
                    ->where(
                        'game.data.planningBundleDescriptions.factions.'.$bundle->faction->value,
                        $bundle->faction->description(),
                    )
                    ->where(
                        'game.data.planningBundleDescriptions.roundBonuses.'.$bundle->roundBonus->value,
                        $bundle->roundBonus->description(),
                    )
                    ->has('game.data.playerBoardStates', 1)
                    ->where('game.data.playerBoardStates.0.playerId', $player->id)
                    ->where(
                        'game.data.playerBoardStates.0.victoryPoints',
                        $game->state->players[0]->victoryPoints,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.roundBonus',
                        $game->state->players[0]->roundBonus->value,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.scholars',
                        $game->state->players[0]->resources->scholars,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.scholarDisciplineIds',
                        $game->state->players[0]->scholarDisciplineIds,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.scholarPoolSize',
                        $game->state->players[0]->scholarPoolSize,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.coins',
                        $game->state->players[0]->resources->coins,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.tools',
                        $game->state->players[0]->resources->tools,
                    )
                    ->where('game.data.playerBoardStates.0.books', [
                        'banking' => $game->state->players[0]
                            ->resources->books->banking,
                        'law' => $game->state->players[0]
                            ->resources->books->law,
                        'engineering' => $game->state->players[0]
                            ->resources->books->engineering,
                        'medicine' => $game->state->players[0]
                            ->resources->books->medicine,
                        'unassigned' => $game->state->players[0]
                            ->resources->books->unassigned,
                    ])
                    ->where(
                        'game.data.playerBoardStates.0.availableBridges',
                        3,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.competencyIds',
                        $game->state->players[0]->competencyIds,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.palaceId',
                        $game->state->players[0]->palaceId,
                    )
                    ->where('game.data.playerBoardStates.0.activeTownKeys', 0)
                    ->where('game.data.playerBoardStates.0.usedTownKeys', 0)
                    ->where('game.data.playerBoardStates.0.activeAnnexes', 0)
                    ->where('game.data.playerBoardStates.0.availableAnnexes', 0)
                    ->where(
                        'game.data.playerBoardStates.0.income',
                        PlayerIncomeCalculator::calculate(
                            $game->state->players[0],
                            $game->state->board,
                        ),
                    )
                    ->where(
                        'game.data.playerBoardStates.0.shippingLevel',
                        $game->state->players[0]->shippingLevel,
                    )
                    ->where('game.data.playerBoardStates.0.terraformingLevel', 0)
                    ->where(
                        'game.data.playerBoardStates.0.knowledge.banking',
                        $game->state->players[0]->knowledge->banking,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.knowledge.law',
                        $game->state->players[0]->knowledge->law,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.knowledge.engineering',
                        $game->state->players[0]->knowledge->engineering,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.knowledge.medicine',
                        $game->state->players[0]->knowledge->medicine,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.power.bowlOne',
                        $game->state->players[0]->resources->power->bowlOne,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.power.bowlTwo',
                        $game->state->players[0]->resources->power->bowlTwo,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.power.bowlThree',
                        $game->state->players[0]->resources->power->bowlThree,
                    )
                    ->has('game.data.roundScoringTiles', 6)
                    ->where(
                        'game.data.finalRoundScoringTile',
                        $game->state->setupPool->additionalFinalRoundGoal->value,
                    )
                    ->has('game.data.bookActions', 3)
                    ->has('game.data.usedBookActionIds', 0)
                    ->has('game.data.powerActions', 6)
                    ->where('game.data.powerActions.0.id', 'build_bridge')
                    ->where('game.data.powerActions.0.cost', 3)
                    ->where(
                        'game.data.powerActions.0.description',
                        'Потратить 3 силы, чтобы построить мост.',
                    )
                    ->where('game.data.powerActions.0.isUsed', false)
                    ->where('game.data.powerActions.5.id', 'terraform_two_spades')
                    ->where('game.data.powerActions.5.cost', 6)
                    ->has('game.data.innovations', 6)
                    ->has('game.data.competencies', 12),
            );
    }

    public function test_inactive_player_cannot_choose_planning_bundle(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $otherUser->id,
            'seat' => 2,
        ]);

        $this->actingAs($owner)->post(route('games.start', $game));
        $game->refresh();

        $inactiveUser = $game->active_player_id === $owner->id ? $otherUser : $owner;
        $bundle = $game->state->setupPool->planningBundles[0];

        $this->actingAs($inactiveUser)
            ->post(route('games.planning-bundle.store', $game), [
                'homeland' => $bundle->homeland->value,
            ])
            ->assertForbidden();

        $this->assertCount(7, $game->refresh()->state->setupPool->planningBundles);
    }

    public function test_player_must_distribute_starting_resources_before_the_next_player_chooses(): void
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create(['random_seed' => 'starting-resources-seed']);

        foreach ($users as $index => $user) {
            GamePlayer::factory()->ready()->create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'seat' => $index + 1,
            ]);
        }

        $this->actingAs($users[0])->post(route('games.start', $game));
        $game->refresh();

        $activeUser = $users->firstWhere('id', $game->active_player_id);
        $state = $game->state;
        $bundle = collect($state->setupPool->planningBundles)->first(
            static fn (PlanningBundleData $bundle): bool => $bundle->homeland === TerrainType::Wasteland,
        );

        $this->assertInstanceOf(User::class, $activeUser);
        $this->assertInstanceOf(PlanningBundleData::class, $bundle);

        $bundle->faction = Faction::Lizards;
        $game->update(['state' => $state]);

        $this->actingAs($activeUser)
            ->post(route('games.planning-bundle.store', $game), [
                'homeland' => TerrainType::Wasteland->value,
            ])
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $player = $game->players()->whereBelongsTo($activeUser)->sole();

        $this->assertSame($activeUser->id, $game->active_player_id);
        $this->assertSame(PendingInteractionType::ChooseStartingResources, $game->state->pendingInteraction?->type);
        $this->assertSame($player->id, $game->state->pendingInteraction?->playerId);
        $this->assertSame(1, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(2, $game->state->players[0]->knowledge->unassignedSteps);

        $this->post(route('games.starting-resources.store', $game))
            ->assertSessionHasErrors(['book_counts', 'knowledge_counts']);

        $this->assertNotNull($game->refresh()->state->pendingInteraction);

        $this->post(route('games.starting-resources.store', $game), [
            'book_counts' => [
                'banking' => 1,
                'law' => 1,
                'engineering' => 0,
                'medicine' => 0,
            ],
            'knowledge_counts' => [
                'banking' => 0,
                'law' => 2,
                'engineering' => 0,
                'medicine' => 0,
            ],
        ])->assertSessionHasErrors('book_counts');

        $game->refresh();

        $this->assertNotNull($game->state->pendingInteraction);
        $this->assertSame(1, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(0, $game->state->players[0]->resources->books->banking);
        $this->assertSame(0, $game->state->players[0]->resources->books->law);
        $this->assertSame(2, $game->state->players[0]->knowledge->unassignedSteps);

        $this->post(route('games.starting-resources.store', $game), [
            'book_counts' => [
                'banking' => 1,
                'law' => 0,
                'engineering' => 0,
                'medicine' => 0,
            ],
            'knowledge_counts' => [
                'banking' => 1,
                'law' => 2,
                'engineering' => 0,
                'medicine' => 0,
            ],
        ])->assertSessionHasErrors('knowledge_counts');

        $game->refresh();

        $this->assertSame(2, $game->state->players[0]->knowledge->unassignedSteps);
        $this->assertSame(0, $game->state->players[0]->knowledge->banking);
        $this->assertSame(0, $game->state->players[0]->knowledge->law);

        $this->post(route('games.starting-resources.store', $game), [
            'book_counts' => [
                'banking' => 1,
                'law' => 0,
                'engineering' => 0,
                'medicine' => 0,
            ],
            'knowledge_counts' => [
                'banking' => 0,
                'law' => 2,
                'engineering' => 0,
                'medicine' => 0,
            ],
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();

        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(0, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(1, $game->state->players[0]->resources->books->banking);
        $this->assertSame(0, $game->state->players[0]->knowledge->unassignedSteps);
        $this->assertSame(2, $game->state->players[0]->knowledge->law);
        $this->assertNotSame($activeUser->id, $game->active_player_id);

        $inventorUser = $users->firstWhere('id', $game->active_player_id);
        $this->assertInstanceOf(User::class, $inventorUser);

        $state = $game->state;
        $inventorBundle = collect($state->setupPool->planningBundles)->first(
            static fn (PlanningBundleData $bundle): bool => $bundle->homeland === TerrainType::Forest,
        );
        $this->assertInstanceOf(PlanningBundleData::class, $inventorBundle);

        $inventorBundle->faction = Faction::Inventors;
        $otherCompetencies = collect($state->setupPool->competencies)
            ->reject(
                static fn (Competency|string $competency): bool => ($competency instanceof Competency
                    ? $competency->value
                    : $competency) === Competency::Competency01->value,
            )
            ->values();
        $state->setupPool->competencies = [
            ...$otherCompetencies->take(4)->all(),
            Competency::Competency01,
            ...$otherCompetencies->skip(4)->all(),
        ];
        $game->update(['state' => $state]);

        $this->actingAs($inventorUser)
            ->post(route('games.planning-bundle.store', $game), [
                'homeland' => $inventorBundle->homeland->value,
            ])
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $inventorPlayer = $game->players()->whereBelongsTo($inventorUser)->sole();

        $this->assertSame($inventorPlayer->id, $game->state->pendingInteraction?->playerId);
        $this->assertSame(
            array_map(
                static fn (Competency|string $competency): string => $competency instanceof Competency
                    ? $competency->value
                    : $competency,
                $game->state->setupPool->competencies,
            ),
            $game->state->pendingInteraction?->context['competencyIds'],
        );

        $this->post(route('games.starting-resources.store', $game))
            ->assertSessionHasErrors('competency_id');

        $this->post(route('games.starting-resources.store', $game), [
            'competency_id' => Competency::Competency01->value,
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $inventorState = collect($game->state->players)->firstWhere('playerId', $inventorPlayer->id);

        $this->assertInstanceOf(GamePlayerStateData::class, $inventorState);
        $this->assertSame([Competency::Competency01->value], $inventorState->competencyIds);
        $this->assertSame(3, $inventorState->knowledge->banking);
        $this->assertSame(1, $inventorState->resources->books->banking);
        $this->assertSame(3, $inventorState->resources->power->bowlOne);
        $this->assertSame(9, $inventorState->resources->power->bowlTwo);
        $this->assertSame(0, $inventorState->resources->power->bowlThree);
    }

    #[DataProvider('immediateStartingCompetencyEffects')]
    public function test_inventors_receive_immediate_starting_competency_effects(
        Competency $competency,
        int $coins,
        int $tools,
        int $victoryPoints,
        int $unassignedSpades,
        int $availableAnnexes,
    ): void {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create(['random_seed' => 'immediate-competency-effects-seed']);

        foreach ($users as $index => $user) {
            GamePlayer::factory()->ready()->create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'seat' => $index + 1,
            ]);
        }

        $this->actingAs($users[0])->post(route('games.start', $game));
        $game->refresh();

        $activeUser = $users->firstWhere('id', $game->active_player_id);
        $this->assertInstanceOf(User::class, $activeUser);

        $state = $game->state;
        $bundle = collect($state->setupPool->planningBundles)->first(
            static fn (PlanningBundleData $bundle): bool => $bundle->homeland === TerrainType::Plains,
        );
        $this->assertInstanceOf(PlanningBundleData::class, $bundle);

        $bundle->faction = Faction::Inventors;
        $game->update(['state' => $state]);

        $this->actingAs($activeUser)
            ->post(route('games.planning-bundle.store', $game), [
                'homeland' => $bundle->homeland->value,
            ])
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $player = $game->players()->whereBelongsTo($activeUser)->sole();
        $playerStateBeforeCompetency = collect($game->state->players)->firstWhere('playerId', $player->id);
        $this->assertInstanceOf(GamePlayerStateData::class, $playerStateBeforeCompetency);

        $this->post(route('games.starting-resources.store', $game), [
            'competency_id' => $competency->value,
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $playerState = collect($game->state->players)->firstWhere('playerId', $player->id);
        $this->assertInstanceOf(GamePlayerStateData::class, $playerState);
        $this->assertSame($playerStateBeforeCompetency->resources->coins + $coins, $playerState->resources->coins);
        $this->assertSame($playerStateBeforeCompetency->resources->tools + $tools, $playerState->resources->tools);
        $this->assertSame($playerStateBeforeCompetency->victoryPoints + $victoryPoints, $playerState->victoryPoints);
        $this->assertSame($unassignedSpades, $playerState->unassignedSpades);
        $this->assertSame($availableAnnexes, $playerState->availableAnnexes);
    }

    /** @return iterable<string, array{Competency, int, int, int, int, int}> */
    public static function immediateStartingCompetencyEffects(): iterable
    {
        yield 'competency_04 gives coins, a tool, and victory points' => [
            Competency::Competency04,
            2,
            1,
            5,
            0,
            0,
        ];
        yield 'competency_05 gives two unassigned spades' => [
            Competency::Competency05,
            0,
            0,
            0,
            2,
            0,
        ];
        yield 'competency_06 gives two available annexes' => [
            Competency::Competency06,
            0,
            0,
            0,
            0,
            2,
        ];
    }

    public function test_active_player_can_place_cancel_and_confirm_a_starting_building(): void
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Setup,
            'active_player_id' => $users[0]->id,
        ]);
        $firstPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[0]->id,
            'seat' => 1,
            'color' => PlayerColor::Yellow,
            'faction' => Faction::Blessed,
            'homeland' => TerrainType::Forest,
        ]);
        $secondPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[1]->id,
            'seat' => 2,
            'color' => PlayerColor::Red,
            'faction' => Faction::Felines,
            'homeland' => TerrainType::Mountain,
        ]);
        $firstBundle = new PlanningBundleData(TerrainType::Forest, Faction::Blessed, RoundBonus::Coins);
        $secondBundle = new PlanningBundleData(TerrainType::Mountain, Faction::Felines, RoundBonus::PowerCoins);
        $board = (new BoardStateFactory())->create(MapVariant::OneToThreePlayers);
        $forestHex = collect($board->hexes)->firstWhere('terrain', TerrainType::Forest);
        $mountainHex = collect($board->hexes)->firstWhere('terrain', TerrainType::Mountain);

        $this->assertNotNull($forestHex);
        $this->assertNotNull($mountainHex);

        $game->update([
            'state' => new GameStateData(
                schemaVersion: 3,
                turnOrder: [$firstPlayer->id, $secondPlayer->id],
                board: $board,
                players: [
                    new GamePlayerStateData(
                        $firstPlayer->id,
                        $users[0]->id,
                        PlayerColor::Yellow,
                        Faction::Blessed,
                        TerrainType::Forest,
                        RoundBonus::Coins,
                    ),
                    new GamePlayerStateData(
                        $secondPlayer->id,
                        $users[1]->id,
                        PlayerColor::Red,
                        Faction::Felines,
                        TerrainType::Mountain,
                        RoundBonus::PowerCoins,
                    ),
                ],
                planningSelections: [
                    new PlayerPlanningSelectionData($firstPlayer->id, $firstBundle),
                    new PlayerPlanningSelectionData($secondPlayer->id, $secondBundle),
                ],
            ),
        ]);

        $this->actingAs($users[1])
            ->post(route('games.starting-building.store', $game), ['hex_id' => $forestHex->id])
            ->assertForbidden();

        $this->actingAs($users[0])
            ->post(route('games.starting-building.finish', $game))
            ->assertSessionHasErrors('game');

        $this->post(route('games.starting-building.store', $game), ['hex_id' => $mountainHex->id])
            ->assertSessionHasErrors('hex_id');

        $this
            ->post(route('games.starting-building.store', $game), ['hex_id' => $forestHex->id])
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame($forestHex->id, $game->state->pendingStartingBuildingHexId);
        $this->assertSame($firstPlayer->id, collect($game->state->board->hexes)->firstWhere('id', $forestHex->id)?->building?->ownerPlayerId);
        $this->assertCount(0, $game->actions);
        $this->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('game.data.playerBoardStates.0.buildingsOnMap.workshop', 1),
            );

        $this->delete(route('games.starting-building.destroy', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertNull($game->state->pendingStartingBuildingHexId);
        $this->assertNull(collect($game->state->board->hexes)->firstWhere('id', $forestHex->id)?->building);
        $this->assertCount(0, $game->actions);
        $this->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('game.data.playerBoardStates.0.buildingsOnMap.workshop', 0),
            );

        $this->post(route('games.starting-building.store', $game), ['hex_id' => $forestHex->id]);
        $this->post(route('games.starting-building.finish', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(1, $game->state->startingBuildingTurnIndex);
        $this->assertNull($game->state->pendingStartingBuildingHexId);
        $this->assertSame($users[1]->id, $game->active_player_id);

        $actions = $game->actions()->orderBy('sequence')->get();

        $this->assertCount(1, $actions);
        $this->assertSame([GameActionType::PlaceStartingBuilding], $actions->pluck('type')->all());
        $this->assertSame([1], $actions->pluck('sequence')->all());
        $this->assertSame($forestHex->id, $actions[0]->payload['hex_id']);
        $this->assertTrue($actions[0]->payload['confirmed']);
        $this->assertSame('starting_building_placed', $actions[0]->events[0]['type']);
        $this->assertSame(0, $actions[0]->state_version_before);
        $this->assertSame(1, $actions[0]->state_version_after);
    }

    public function test_desert_player_spends_starting_spade_after_all_starting_buildings_are_placed(): void
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Setup,
            'active_player_id' => $users[0]->id,
        ]);
        $desertPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[0]->id,
            'seat' => 1,
            'color' => PlayerColor::Yellow,
            'faction' => Faction::Blessed,
            'homeland' => TerrainType::Desert,
        ]);
        $otherPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[1]->id,
            'seat' => 2,
            'color' => PlayerColor::Green,
            'faction' => Faction::Felines,
            'homeland' => TerrainType::Forest,
        ]);
        $board = (new BoardStateFactory())->create(MapVariant::OneToThreePlayers);
        $hexesById = collect($board->hexes)->keyBy('id');
        $desertHex = collect($board->hexes)->first(function ($hex) use ($hexesById): bool {
            if ($hex->terrain !== TerrainType::Desert) {
                return false;
            }

            return collect($hex->adjacentHexIds)->contains(
                fn (string $hexId): bool => $hexesById->get($hexId)?->terrain->isHomeland() === true
                    && ! in_array(
                        $hexesById->get($hexId)?->terrain,
                        [TerrainType::Desert, TerrainType::Plains, TerrainType::Wasteland],
                        true,
                    ),
            );
        });

        $this->assertNotNull($desertHex);
        $targetHexId = collect($desertHex->adjacentHexIds)->first(
            fn (string $hexId): bool => $hexesById->get($hexId)?->terrain->isHomeland() === true
                && ! in_array(
                    $hexesById->get($hexId)?->terrain,
                    [TerrainType::Desert, TerrainType::Plains, TerrainType::Wasteland],
                    true,
                ),
        );
        $this->assertIsString($targetHexId);
        $targetTerrainBefore = $hexesById->get($targetHexId)?->terrain;
        $this->assertInstanceOf(TerrainType::class, $targetTerrainBefore);
        $targetTerrainAfter = $targetTerrainBefore->stepTowards(TerrainType::Desert);

        $desertHex->building = new BuildingStateData(BuildingType::Workshop, $desertPlayer->id);
        $desertBundle = new PlanningBundleData(TerrainType::Desert, Faction::Blessed, RoundBonus::Coins);
        $otherBundle = new PlanningBundleData(TerrainType::Forest, Faction::Felines, RoundBonus::PowerCoins);

        $game->update([
            'state' => new GameStateData(
                turnOrder: [$desertPlayer->id, $otherPlayer->id],
                board: $board,
                players: [
                    new GamePlayerStateData(
                        playerId: $desertPlayer->id,
                        userId: $users[0]->id,
                        color: PlayerColor::Yellow,
                        faction: Faction::Blessed,
                        homeland: TerrainType::Desert,
                        roundBonus: RoundBonus::Coins,
                        unassignedSpades: 1,
                    ),
                    new GamePlayerStateData(
                        playerId: $otherPlayer->id,
                        userId: $users[1]->id,
                        color: PlayerColor::Green,
                        faction: Faction::Felines,
                        homeland: TerrainType::Forest,
                        roundBonus: RoundBonus::PowerCoins,
                    ),
                ],
                planningSelections: [
                    new PlayerPlanningSelectionData($desertPlayer->id, $desertBundle),
                    new PlayerPlanningSelectionData($otherPlayer->id, $otherBundle),
                ],
                startingBuildingTurnIndex: 3,
                pendingStartingBuildingHexId: $desertHex->id,
            ),
        ]);

        $this->actingAs($users[0])
            ->post(route('games.starting-building.finish', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(GamePhase::Setup, $game->phase);
        $this->assertSame($users[0]->id, $game->active_player_id);
        $this->assertSame(PendingInteractionType::SpendSpades, $game->state->pendingInteraction?->type);
        $this->assertContains($targetHexId, $game->state->pendingInteraction?->optionIds);
        $historyCountBeforeSelection = $game->actions()->count();

        $this->post(route('games.starting-spade.finish', $game))
            ->assertSessionHasErrors('game');

        $this->post(route('games.starting-spade.store', $game), ['hex_id' => $desertHex->id])
            ->assertSessionHasErrors('hex_id');

        $game->refresh();
        $this->assertSame(PendingInteractionType::SpendSpades, $game->state->pendingInteraction?->type);

        $this->post(route('games.starting-spade.store', $game), ['hex_id' => $targetHexId])
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $desertPlayerState = collect($game->state->players)->firstWhere('playerId', $desertPlayer->id);
        $this->assertSame(GamePhase::Setup, $game->phase);
        $this->assertSame($targetHexId, $game->state->pendingInteraction?->context['selectedHexId']);
        $this->assertSame($targetTerrainAfter, collect($game->state->board->hexes)->firstWhere('id', $targetHexId)?->terrain);
        $this->assertSame(1, $desertPlayerState?->unassignedSpades);
        $this->assertCount($historyCountBeforeSelection, $game->actions);

        $this->delete(route('games.starting-spade.destroy', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame($targetTerrainBefore, collect($game->state->board->hexes)->firstWhere('id', $targetHexId)?->terrain);
        $this->assertArrayNotHasKey('selectedHexId', $game->state->pendingInteraction?->context ?? []);
        $this->assertCount($historyCountBeforeSelection, $game->actions);

        $this->post(route('games.starting-spade.store', $game), ['hex_id' => $targetHexId]);
        $this->post(route('games.starting-spade.finish', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $desertPlayerState = collect($game->state->players)->firstWhere('playerId', $desertPlayer->id);
        $this->assertSame(GamePhase::Actions, $game->phase);
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame($targetTerrainAfter, collect($game->state->board->hexes)->firstWhere('id', $targetHexId)?->terrain);
        $this->assertSame(0, $desertPlayerState?->unassignedSpades);
        $this->assertCount($historyCountBeforeSelection + 2, $game->actions);
        $this->assertSame(
            GameActionType::SpendStartingSpade,
            $game->actions()->where('type', '!=', GameActionType::PhaseCheckpoint)->latest('sequence')->firstOrFail()->type,
        );

        $targetHexIndex = collect($game->state->board->hexes)->search(
            static fn (BoardHexStateData $hex): bool => $hex->id === $targetHexId,
        );
        $this->assertIsInt($targetHexIndex);
        $this->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where("game.data.board.hexes.{$targetHexIndex}.initialTerrain", $targetTerrainBefore->value)
                    ->where("game.data.board.hexes.{$targetHexIndex}.terrain", $targetTerrainAfter->value),
            );
    }

    public function test_game_applies_income_and_enters_actions_when_no_income_choices_are_required(): void
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Setup,
            'active_player_id' => $users[0]->id,
        ]);
        $firstPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[0]->id,
            'seat' => 1,
            'color' => PlayerColor::Green,
            'faction' => Faction::Blessed,
            'homeland' => TerrainType::Forest,
        ]);
        $secondPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[1]->id,
            'seat' => 2,
            'color' => PlayerColor::Grey,
            'faction' => Faction::Felines,
            'homeland' => TerrainType::Mountain,
        ]);
        $board = (new BoardStateFactory())->create(MapVariant::OneToThreePlayers);
        $forestHexIds = collect($board->hexes)
            ->where('terrain', TerrainType::Forest)
            ->take(2)
            ->pluck('id')
            ->all();
        $mountainHexIds = collect($board->hexes)
            ->where('terrain', TerrainType::Mountain)
            ->take(2)
            ->pluck('id')
            ->all();

        $this->assertCount(2, $forestHexIds);
        $this->assertCount(2, $mountainHexIds);

        $firstBundle = new PlanningBundleData(
            TerrainType::Forest,
            Faction::Blessed,
            RoundBonus::Coins,
        );
        $secondBundle = new PlanningBundleData(
            TerrainType::Mountain,
            Faction::Felines,
            RoundBonus::PowerCoins,
        );
        $playerStateFactory = app(GamePlayerStateFactory::class);

        $game->update([
            'state' => new GameStateData(
                schemaVersion: 3,
                turnOrder: [$firstPlayer->id, $secondPlayer->id],
                board: $board,
                players: [
                    $playerStateFactory->create($firstPlayer, $firstBundle),
                    $playerStateFactory->create($secondPlayer, $secondBundle),
                ],
                planningSelections: [
                    new PlayerPlanningSelectionData(
                        $firstPlayer->id,
                        $firstBundle,
                    ),
                    new PlayerPlanningSelectionData(
                        $secondPlayer->id,
                        $secondBundle,
                    ),
                ],
            ),
        ]);
        $resourcesBeforeIncome = collect($game->state->players)->mapWithKeys(
            static fn (GamePlayerStateData $playerState): array => [
                $playerState->playerId => [
                    'tools' => $playerState->resources->tools,
                    'coins' => $playerState->resources->coins,
                    'scholars' => $playerState->resources->scholars,
                ],
            ],
        );

        $placements = [
            [$users[0], $forestHexIds[0]],
            [$users[1], $mountainHexIds[0]],
            [$users[1], $mountainHexIds[1]],
            [$users[0], $forestHexIds[1]],
        ];

        foreach ($placements as [$user, $hexId]) {
            $this->actingAs($user)
                ->post(route('games.starting-building.store', $game), ['hex_id' => $hexId])
                ->assertRedirect(route('games.show', $game));
            $this->post(route('games.starting-building.finish', $game))
                ->assertRedirect(route('games.show', $game));
        }

        $game->refresh();

        $this->assertSame(4, $game->state->startingBuildingTurnIndex);
        $this->assertSame(GamePhase::Actions, $game->phase);
        $this->assertSame(GamePhase::Actions, $game->state->round->phase);
        $this->assertSame($users[0]->id, $game->active_player_id);
        $this->assertNull($game->state->pendingStartingBuildingHexId);
        $this->assertNull($game->state->pendingInteraction);
        $this->assertCount(5, $game->actions);
        $this->assertTrue($game->actions->where('type', '!=', GameActionType::PhaseCheckpoint)->every(
            static fn (GameAction $action): bool => $action->type === GameActionType::PlaceStartingBuilding,
        ));
        $incomeStartingAction = $game->actions()
            ->where('type', '!=', GameActionType::PhaseCheckpoint)
            ->latest('sequence')
            ->firstOrFail();
        $this->assertTrue($incomeStartingAction->payload['income_started']);
        $this->assertSame(1, $incomeStartingAction->payload['round']);
        $this->assertSame('income_phase_started', $incomeStartingAction->events[1]['type']);
        $this->assertSame(1, $incomeStartingAction->events[1]['round']);
        $this->assertCount(2, $incomeStartingAction->payload['income_receipts']);
        $this->assertEqualsCanonicalizing(
            [$firstPlayer->id, $secondPlayer->id],
            array_column($incomeStartingAction->payload['income_receipts'], 'player_id'),
        );

        foreach ($game->state->players as $playerState) {
            $income = PlayerIncomeCalculator::calculate($playerState, $game->state->board);
            $resourcesBefore = $resourcesBeforeIncome->get($playerState->playerId);

            $this->assertIsArray($resourcesBefore);
            $this->assertSame($resourcesBefore['tools'] + $income['tools'], $playerState->resources->tools);
            $this->assertSame($resourcesBefore['coins'] + $income['coins'], $playerState->resources->coins);
            $this->assertSame($resourcesBefore['scholars'] + $income['scholars'], $playerState->resources->scholars);
        }

        $activePlayerState = collect($game->state->players)->firstWhere('userId', $users[0]->id);
        $this->assertInstanceOf(GamePlayerStateData::class, $activePlayerState);
        $this->assertGreaterThanOrEqual(4, $activePlayerState->resources->power->bowlTwo);
        $bowlTwoAtTurnStart = $activePlayerState->resources->power->bowlTwo;
        $bowlThreeAtTurnStart = $activePlayerState->resources->power->bowlThree;

        $this->actingAs($users[0]);
        $this->post(route('games.power-sacrifice.store', $game), ['amount' => 1]);
        $this->post(route('games.power-sacrifice.store', $game), ['amount' => 1]);

        $game->refresh();
        $this->assertCount(7, $game->actions);
        $this->assertSame(4, $game->state->round->turnStartVersion);
        $this->assertSame($bowlTwoAtTurnStart - 4, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame($bowlThreeAtTurnStart + 2, $game->state->players[0]->resources->power->bowlThree);
        $this->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('game.data.canRestartCurrentTurn', true)
                    ->where('game.data.canFinishCurrentTurn', false),
            );
        $this->post(route('games.current-turn.finish', $game))->assertForbidden();

        $this->actingAs($users[1])
            ->post(route('games.current-turn.restart', $game))
            ->assertForbidden();

        $this->actingAs($users[0])
            ->post(route('games.current-turn.restart', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $restartedPlayerState = collect($game->state->players)->firstWhere('userId', $users[0]->id);
        $this->assertInstanceOf(GamePlayerStateData::class, $restartedPlayerState);
        $this->assertSame($bowlTwoAtTurnStart, $restartedPlayerState->resources->power->bowlTwo);
        $this->assertSame($bowlThreeAtTurnStart, $restartedPlayerState->resources->power->bowlThree);
        $this->assertSame($users[0]->id, $game->active_player_id);
        $this->assertSame(GamePhase::Actions, $game->phase);
        $this->assertNull($game->state->round->turnStartVersion);
        $this->assertSame(4, $game->version);
        $this->assertCount(4, $game->actions);
    }

    public function test_setup_spends_competency_five_spades_without_building(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Setup,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
            'color' => PlayerColor::Green,
            'faction' => Faction::Inventors,
            'homeland' => TerrainType::Forest,
        ]);
        $playerState = new GamePlayerStateData(
            playerId: $player->id,
            userId: $user->id,
            color: PlayerColor::Green,
            faction: Faction::Inventors,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            unassignedSpades: 2,
            competencyIds: [Competency::Competency05->value],
        );
        $board = (new BoardStateFactory())->create(MapVariant::OneToThreePlayers);

        foreach ($board->hexes as $hex) {
            if ($hex->terrain === TerrainType::Forest) {
                $hex->building = new BuildingStateData(BuildingType::Workshop, $player->id);
            }
        }

        $targets = collect($board->hexes)
            ->filter(fn (BoardHexStateData $hex): bool => in_array(
                $hex->terrain,
                [TerrainType::Lake, TerrainType::Mountain],
                true,
            ) && collect($hex->adjacentHexIds)->contains(
                fn (string $adjacentId): bool => collect($board->hexes)->firstWhere('id', $adjacentId)?->building?->ownerPlayerId === $player->id,
            ))
            ->take(2)
            ->values();
        $this->assertCount(2, $targets);
        $initialBuildingCount = collect($board->hexes)->whereNotNull('building')->count();
        $state = new GameStateData(
            turnOrder: [$player->id],
            board: $board,
            players: [$playerState],
        );

        [, $phase] = app(ResolveCompletedStartingSetupAction::class)->execute($state, $game->players()->get());
        $game->update(['state' => $state]);

        $this->assertSame(GamePhase::Setup, $phase);
        $this->assertSame(PendingInteractionType::SpendSpades, $state->pendingInteraction?->type);

        foreach ($targets as $index => $target) {
            $this->actingAs($user)->post(route('games.starting-spade.store', $game), ['hex_id' => $target->id]);
            $this->post(route('games.starting-spade.finish', $game));
            $game->refresh();

            if ($index === 0) {
                $this->assertSame(1, $game->state->pendingInteraction?->context['remainingSpades']);
            }
        }

        $finalPlayerState = collect($game->state->players)->firstWhere('playerId', $player->id);
        $this->assertSame(GamePhase::Actions, $game->phase);
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(0, $finalPlayerState?->unassignedSpades);
        $this->assertSame($initialBuildingCount, collect($game->state->board->hexes)->whereNotNull('building')->count());

        foreach ($targets as $target) {
            $this->assertNull(collect($game->state->board->hexes)->firstWhere('id', $target->id)?->building);
        }
    }

    public function test_omar_places_a_neutral_tower_after_regular_buildings_and_before_monks(): void
    {
        $users = User::factory()->count(3)->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Setup,
            'active_player_id' => $users[1]->id,
        ]);
        $regularPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[0]->id,
            'seat' => 1,
            'faction' => Faction::Blessed,
            'homeland' => TerrainType::Mountain,
        ]);
        $omarPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[1]->id,
            'seat' => 2,
            'faction' => Faction::Omar,
            'homeland' => TerrainType::Forest,
        ]);
        $monkPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[2]->id,
            'seat' => 3,
            'faction' => Faction::Monks,
            'homeland' => TerrainType::Wasteland,
        ]);
        $board = (new BoardStateFactory())->create(MapVariant::OneToThreePlayers);
        $forestHexes = collect($board->hexes)->where('terrain', TerrainType::Forest)->take(3)->values();

        $this->assertCount(3, $forestHexes);

        foreach ($forestHexes->take(2) as $forestHex) {
            $forestHex->building = new BuildingStateData(BuildingType::Workshop, $omarPlayer->id);
        }

        $game->update([
            'state' => new GameStateData(
                schemaVersion: 3,
                turnOrder: [$regularPlayer->id, $omarPlayer->id, $monkPlayer->id],
                board: $board,
                planningSelections: [
                    new PlayerPlanningSelectionData(
                        $regularPlayer->id,
                        new PlanningBundleData(TerrainType::Mountain, Faction::Blessed, RoundBonus::Coins),
                    ),
                    new PlayerPlanningSelectionData(
                        $omarPlayer->id,
                        new PlanningBundleData(TerrainType::Forest, Faction::Omar, RoundBonus::PowerCoins),
                    ),
                    new PlayerPlanningSelectionData(
                        $monkPlayer->id,
                        new PlanningBundleData(TerrainType::Wasteland, Faction::Monks, RoundBonus::Coins),
                    ),
                ],
                startingBuildingTurnIndex: 4,
            ),
        ]);

        $this->assertSame(
            [
                $regularPlayer->id,
                $omarPlayer->id,
                $omarPlayer->id,
                $regularPlayer->id,
                $omarPlayer->id,
                $monkPlayer->id,
            ],
            app(DetermineStartingBuildingOrderAction::class)->execute($game->refresh()),
        );

        $towerHex = $forestHexes[2];
        $this->actingAs($users[1])
            ->post(route('games.starting-building.store', $game), ['hex_id' => $towerHex->id])
            ->assertRedirect(route('games.show', $game));

        $building = collect($game->refresh()->state->board->hexes)->firstWhere('id', $towerHex->id)?->building;
        $this->assertSame(BuildingType::Tower, $building?->type);
        $this->assertTrue($building?->isNeutral);
    }

    public function test_monks_place_a_university_last_and_choose_a_starting_competency(): void
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Setup,
            'active_player_id' => $users[1]->id,
        ]);
        $monkPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[0]->id,
            'seat' => 1,
            'color' => PlayerColor::Yellow,
            'faction' => Faction::Monks,
            'homeland' => TerrainType::Mountain,
        ]);
        $regularPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[1]->id,
            'seat' => 2,
            'color' => PlayerColor::Red,
            'faction' => Faction::Blessed,
            'homeland' => TerrainType::Forest,
        ]);
        $monkBundle = new PlanningBundleData(TerrainType::Mountain, Faction::Monks, RoundBonus::Coins);
        $regularBundle = new PlanningBundleData(TerrainType::Forest, Faction::Blessed, RoundBonus::PowerCoins);
        $board = (new BoardStateFactory())->create(MapVariant::OneToThreePlayers);
        $forestHexes = collect($board->hexes)->where('terrain', TerrainType::Forest)->take(2)->values();
        $mountainHex = collect($board->hexes)->firstWhere('terrain', TerrainType::Mountain);
        $setupPool = (new GameSetupPoolFactory())->create(2, MapVariant::OneToThreePlayers);
        $setupPool->competencies = Competency::cases();
        $monkState = new GamePlayerStateData(
            $monkPlayer->id,
            $users[0]->id,
            PlayerColor::Yellow,
            Faction::Monks,
            TerrainType::Mountain,
            RoundBonus::Coins,
        );
        $monkState->competencyIds = [Competency::Competency01->value];
        $regularState = new GamePlayerStateData(
            $regularPlayer->id,
            $users[1]->id,
            PlayerColor::Red,
            Faction::Blessed,
            TerrainType::Forest,
            RoundBonus::PowerCoins,
        );
        $regularState->competencyIds = [Competency::Competency04->value];

        $this->assertCount(2, $forestHexes);
        $this->assertNotNull($mountainHex);

        $game->update([
            'state' => new GameStateData(
                schemaVersion: 3,
                turnOrder: [$monkPlayer->id, $regularPlayer->id],
                board: $board,
                players: [$monkState, $regularState],
                availableCompetencyIds: array_map(
                    static fn (Competency $competency): string => $competency->value,
                    Competency::cases(),
                ),
                setupPool: $setupPool,
                planningSelections: [
                    new PlayerPlanningSelectionData($monkPlayer->id, $monkBundle),
                    new PlayerPlanningSelectionData($regularPlayer->id, $regularBundle),
                ],
            ),
        ]);

        $this->assertSame(
            [$regularPlayer->id, $regularPlayer->id, $monkPlayer->id],
            app(DetermineStartingBuildingOrderAction::class)->execute($game->refresh()),
        );

        foreach ($forestHexes as $forestHex) {
            $this->actingAs($users[1])
                ->post(route('games.starting-building.store', $game), ['hex_id' => $forestHex->id])
                ->assertRedirect(route('games.show', $game));
            $this->post(route('games.starting-building.finish', $game))
                ->assertRedirect(route('games.show', $game));
        }

        $game->refresh();
        $this->assertSame($users[0]->id, $game->active_player_id);
        $this->assertSame(2, $game->state->startingBuildingTurnIndex);

        $this->actingAs($users[0])
            ->post(route('games.starting-building.store', $game), ['hex_id' => $mountainHex->id])
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(
            BuildingType::University,
            collect($game->state->board->hexes)->firstWhere('id', $mountainHex->id)?->building?->type,
        );

        $this->post(route('games.starting-building.finish', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(GamePhase::Setup, $game->phase);
        $this->assertSame(PendingInteractionType::ChooseCompetency, $game->state->pendingInteraction?->type);
        $this->assertCount(11, $game->state->pendingInteraction?->optionIds);
        $this->assertNotContains(Competency::Competency01->value, $game->state->pendingInteraction?->optionIds);
        $this->assertContains(Competency::Competency04->value, $game->state->pendingInteraction?->optionIds);

        $monkStateBefore = collect($game->state->players)->firstWhere('playerId', $monkPlayer->id);

        $this->post(route('games.starting-competency.store', $game), [
            'competency_id' => Competency::Competency01->value,
        ])->assertSessionHasErrors('competency_id');

        $this->post(route('games.starting-competency.store', $game), [
            'competency_id' => Competency::Competency04->value,
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();
        $monkState = collect($game->state->players)->firstWhere('playerId', $monkPlayer->id);
        $this->assertSame(PendingInteractionType::ChooseStartingResources, $game->state->pendingInteraction?->type);
        $this->assertSame($monkPlayer->id, $game->state->pendingInteraction?->playerId);
        $this->assertSame(1, $game->state->pendingInteraction?->context['knowledgeStepCount']);
        $this->assertSame(GamePhase::Income, $game->phase);
        $this->assertContains(Competency::Competency04->value, $monkState->competencyIds);
        $this->assertSame(
            2,
            array_count_values($game->state->availableCompetencyIds)[Competency::Competency04->value],
        );
        $this->assertSame($monkStateBefore->knowledge->medicine + 3, $monkState->knowledge->medicine);
        $monkIncome = PlayerIncomeCalculator::calculate($monkState, $game->state->board);
        $this->assertSame($monkStateBefore->resources->tools + 1 + $monkIncome['tools'], $monkState->resources->tools);
        $this->assertSame($monkStateBefore->resources->coins + 2 + $monkIncome['coins'], $monkState->resources->coins);
        $this->assertSame($monkStateBefore->victoryPoints + 5, $monkState->victoryPoints);
        $this->assertSame(
            GameActionType::ChooseCompetency,
            $game->actions()->where('type', '!=', GameActionType::PhaseCheckpoint)->latest('sequence')->firstOrFail()->type,
        );
    }
}
