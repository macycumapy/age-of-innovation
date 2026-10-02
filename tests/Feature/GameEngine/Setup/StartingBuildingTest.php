<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Setup;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\MapVariant;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Board\Factories\BoardStateFactory;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Setup\Actions\DetermineStartingBuildingOrderAction;
use App\Domain\GameEngine\Setup\Actions\ResolveCompletedStartingSetupAction;
use App\Domain\GameEngine\Setup\Data\PlanningBundleData;
use App\Domain\GameEngine\Setup\Data\PlayerPlanningSelectionData;
use App\Domain\GameEngine\Setup\Factories\GameSetupPoolFactory;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Events\GameChanged;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StartingBuildingTest extends TestCase
{
    use RefreshDatabase;

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
            'faction' => Faction::Inventors,
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

        Event::fake([GameChanged::class]);

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
            ->assertNoContent();

        Event::assertDispatched(
            GameChanged::class,
            static fn (GameChanged $event): bool => $event->gameId === $game->id,
        );

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
            ->assertNoContent();

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
            ->assertNoContent();

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

    public function test_desert_player_spends_starting_spade_after_all_players_place_their_starting_buildings(): void
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
                turnOrder: [$otherPlayer->id, $desertPlayer->id],
                board: $board,
                players: [
                    new GamePlayerStateData(
                        playerId: $desertPlayer->id,
                        userId: $users[0]->id,
                        color: PlayerColor::Yellow,
                        faction: Faction::Blessed,
                        homeland: TerrainType::Desert,
                        roundBonus: RoundBonus::Coins,
                        resources: new PlayerResourcesData(tools: 10),
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
                availableCompetencyIds: array_column(Competency::cases(), 'value'),
                startingBuildingTurnIndex: 3,
                pendingStartingBuildingHexId: $desertHex->id,
            ),
        ]);

        $this->actingAs($users[0])
            ->post(route('games.starting-building.finish', $game))
            ->assertNoContent();

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
            ->assertNoContent();

        $game->refresh();
        $desertPlayerState = collect($game->state->players)->firstWhere('playerId', $desertPlayer->id);
        $this->assertSame(GamePhase::Setup, $game->phase);
        $this->assertSame($targetHexId, $game->state->pendingInteraction?->context['selectedHexId']);
        $this->assertSame($targetTerrainAfter, collect($game->state->board->hexes)->firstWhere('id', $targetHexId)?->terrain);
        $this->assertSame(1, $desertPlayerState?->unassignedSpades);
        $this->assertCount($historyCountBeforeSelection, $game->actions);

        $this->delete(route('games.starting-spade.destroy', $game))
            ->assertNoContent();

        $game->refresh();
        $this->assertSame($targetTerrainBefore, collect($game->state->board->hexes)->firstWhere('id', $targetHexId)?->terrain);
        $this->assertArrayNotHasKey('selectedHexId', $game->state->pendingInteraction?->context ?? []);
        $this->assertCount($historyCountBeforeSelection, $game->actions);

        $this->post(route('games.starting-spade.store', $game), ['hex_id' => $targetHexId]);
        $this->post(route('games.starting-spade.finish', $game))
            ->assertNoContent();

        $game->refresh();
        $desertPlayerState = collect($game->state->players)->firstWhere('playerId', $desertPlayer->id);
        $this->assertSame(GamePhase::Actions, $game->phase);
        $this->assertSame($users[1]->id, $game->active_player_id);
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame($targetTerrainAfter, collect($game->state->board->hexes)->firstWhere('id', $targetHexId)?->terrain);
        $this->assertSame(0, $desertPlayerState?->unassignedSpades);
        $this->assertCount(1, $game->actions()->where('type', GameActionType::SpendStartingSpade)->get());
        $this->assertSame(
            GameActionType::SpendStartingSpade,
            $game->actions()->where('type', GameActionType::SpendStartingSpade)->latest('sequence')->firstOrFail()->type,
        );

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

        $phase = app(ResolveCompletedStartingSetupAction::class)->execute($state)->phase;
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
            ->assertNoContent();

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
                ->assertNoContent();
            $this->post(route('games.starting-building.finish', $game))
                ->assertNoContent();
        }

        $game->refresh();
        $this->assertSame($users[0]->id, $game->active_player_id);
        $this->assertSame(2, $game->state->startingBuildingTurnIndex);

        $this->actingAs($users[0])
            ->post(route('games.starting-building.store', $game), ['hex_id' => $mountainHex->id])
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(
            BuildingType::University,
            collect($game->state->board->hexes)->firstWhere('id', $mountainHex->id)?->building?->type,
        );

        $this->post(route('games.starting-building.finish', $game))
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(GamePhase::Setup, $game->phase);
        $this->assertSame(PendingInteractionType::ChooseCompetency, $game->state->pendingInteraction?->type);
        $this->assertCount(11, $game->state->pendingInteraction?->optionIds);
        $this->assertNotContains(Competency::Competency01->value, $game->state->pendingInteraction?->optionIds);
        $this->assertContains(Competency::Competency04->value, $game->state->pendingInteraction?->optionIds);

        $monkStateBefore = collect($game->state->players)->firstWhere('playerId', $monkPlayer->id);

        $this->post(route('games.rewards', $game), [
            'competency_id' => Competency::Competency01->value,
        ])->assertSessionHasErrors('competency_id');

        $this->post(route('games.rewards', $game), [
            'competency_id' => Competency::Competency04->value,
        ])->assertNoContent();

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
        $this->assertSame($monkStateBefore->resources->tools + 1, $monkState->resources->tools);
        $this->assertSame($monkStateBefore->resources->coins + 2, $monkState->resources->coins);
        $this->assertSame($monkStateBefore->victoryPoints + 5, $monkState->victoryPoints);
        $this->assertSame(
            GameActionType::ChooseCompetency,
            $game->actions()->where('type', '!=', GameActionType::PhaseCheckpoint)->latest('sequence')->firstOrFail()->type,
        );
    }
}
