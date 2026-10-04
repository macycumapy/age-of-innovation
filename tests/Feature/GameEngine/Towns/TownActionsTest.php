<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Towns;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BridgeStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\BookSupplyData;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Data\KnowledgeStateData;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Towns\Actions\FindEligibleTownHexesAction;
use App\Domain\GameEngine\Towns\Enums\TownTile;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TownActionsTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('universityTownCompetencyProvider')]
    public function test_university_upgrade_offers_a_town_for_four_connected_hexes_with_seven_power_after_competency_choice(Competency $competency): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $buildingTypes = [BuildingType::School, BuildingType::Guild, BuildingType::Workshop, BuildingType::Workshop];
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [...array_map(
                static fn (BuildingType $buildingType, int $index): BoardHexStateData => new BoardHexStateData(
                    id: $index.':0',
                    q: $index,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: array_values(array_filter([
                        ($index - 1).':0',
                        ($index + 1).':0',
                    ], static fn (string $id): bool => in_array($id, ['0:0', '1:0', '2:0', '3:0'], true))),
                    building: new BuildingStateData($buildingType, $player->id),
                ),
                $buildingTypes,
                array_keys($buildingTypes),
            ), new BoardHexStateData(
                id: '9:0',
                q: 9,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                adjacentHexIds: ['10:0'],
                building: new BuildingStateData(BuildingType::Workshop, $player->id),
            ), new BoardHexStateData(
                id: '10:0',
                q: 10,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                adjacentHexIds: ['9:0'],
            )]),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 5, coins: 8),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            availableCompetencyIds: [$competency->value],
            availableTownTileIds: [TownTile::Power->value],
        )]);

        $this->actingAs($user)->post(route('games.building-upgrade', $game), [
            'hex_id' => '0:0',
            'target' => BuildingType::University->value,
        ])->assertNoContent();
        $this->assertSame(PendingInteractionType::ChooseCompetency, $game->fresh()->state->pendingInteraction?->type);

        $this->post(route('games.rewards', $game), [
            'competency_id' => $competency->value,
        ])->assertNoContent();

        if ($competency === Competency::Competency10) {
            $this->assertSame(PendingInteractionType::PlaceNeutralBuilding, $game->fresh()->state->pendingInteraction?->type);
            $this->post(route('games.innovation.neutral-building', $game), ['hex_id' => '10:0'])->assertNoContent();
        }

        $game->refresh();
        $this->assertSame(PendingInteractionType::ChooseTown, $game->state->pendingInteraction?->type);
        $this->assertEqualsCanonicalizing(['0:0', '1:0', '2:0', '3:0'], $game->state->pendingInteraction?->context['townHexIds']);
    }

    /** @return array<string, array{Competency}> */
    public static function universityTownCompetencyProvider(): array
    {
        return [
            'regular competency' => [Competency::Competency04],
            'neutral tower placed away from university' => [Competency::Competency10],
        ];
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
        ])->assertNoContent();
        $game->refresh();

        $this->assertSame(30, $game->state->players[0]->victoryPoints);
        $this->assertSame(1, $game->state->players[0]->knowledge->banking);
        $this->assertSame(1, $game->state->players[0]->knowledge->law);
        $this->assertSame(1, $game->state->players[0]->knowledge->engineering);
        $this->assertSame(12, $game->state->players[0]->knowledge->medicine);
        $this->assertSame(10, $game->actions()->sole()->payload['victory_points']);
    }

    public function test_scholar_town_tile_does_not_grant_a_scholar_when_the_player_pool_is_empty(): void
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
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                scholarPoolSize: 0,
            )],
            availableTownTileIds: [TownTile::Scholar->value],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseTown,
                $player->id,
                [TownTile::Scholar->value],
                ['townHexIds' => ['0:0'], 'builtHexId' => '0:0'],
            ),
        )]);

        $this->actingAs($user)->post(route('games.town', $game), [
            'town_tile' => TownTile::Scholar->value,
        ])->assertNoContent();

        $game->refresh();

        $this->assertSame(0, $game->state->players[0]->resources->scholars);
        $this->assertSame(0, $game->state->players[0]->scholarPoolSize);
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

        $this->actingAs($user)->post(route('games.rewards', $game), [
            'book_counts' => ['banking' => 1, 'law' => 0, 'engineering' => 0, 'medicine' => 0],
        ])->assertSessionHasErrors('book_counts');

        $this->post(route('games.rewards', $game), [
            'book_counts' => ['banking' => 1, 'law' => 0, 'engineering' => 0, 'medicine' => 1],
        ])->assertNoContent();
        $game->refresh();

        $this->assertSame(0, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(1, $game->state->players[0]->resources->books->banking);
        $this->assertSame(1, $game->state->players[0]->resources->books->medicine);
        $this->assertSame(GameActionType::ChooseTownBooks, $game->actions()->sole()->type);
    }

    public function test_lizards_can_confirm_or_rollback_their_town_spade_and_build_a_workshop_for_free(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $game->update(['state' => new GameStateData(
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
                ),
                ...array_map(
                    static fn (int $q): BoardHexStateData => new BoardHexStateData(
                        id: $q.':0',
                        q: $q,
                        r: 0,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                        building: new BuildingStateData(BuildingType::Workshop, $player->id),
                    ),
                    range(2, 8),
                ),
                new BoardHexStateData(
                    id: '9:0',
                    q: 9,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(
                        BuildingType::Workshop,
                        $player->id,
                        isNeutral: true,
                    ),
                ),
            ]),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Lizards,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 0, coins: 0),
            )],
            availableTownTileIds: [TownTile::Coins->value],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseTown,
                $player->id,
                [TownTile::Coins->value],
                ['townHexIds' => ['0:0'], 'builtHexId' => '0:0'],
            ),
        )]);

        $this->actingAs($user)->post(route('games.town', $game), [
            'town_tile' => TownTile::Coins->value,
        ])->assertNoContent();
        $game->refresh();

        $this->assertSame(PendingInteractionType::SpendSpades, $game->state->pendingInteraction?->type);
        $this->assertSame(1, $game->state->players[0]->unassignedSpades);
        $this->assertTrue($game->state->pendingInteraction?->context['lizardFreeWorkshop']);

        $this->post(route('games.starting-spade.store', $game), ['hex_id' => '1:0'])->assertNoContent();
        $this->delete(route('games.starting-spade.destroy', $game))->assertNoContent();
        $game->refresh();

        $this->assertSame(TerrainType::Mountain, $game->state->board->hexes[1]->terrain);
        $this->assertArrayNotHasKey('selectedHexId', $game->state->pendingInteraction?->context ?? []);

        $this->post(route('games.starting-spade.store', $game), ['hex_id' => '1:0'])->assertNoContent();
        $this->post(route('games.starting-spade.finish', $game))->assertNoContent();
        $game->refresh();

        $this->assertSame(PendingInteractionType::BuildWorkshopAfterTerraforming, $game->state->pendingInteraction?->type);
        $this->assertSame(0, $game->state->pendingInteraction?->context['toolCost']);
        $this->assertSame(0, $game->state->pendingInteraction?->context['coinCost']);

        $this->post(route('games.terraform-workshop', $game), [
            'build' => true,
            'hex_id' => '1:0',
        ])->assertNoContent();
        $game->refresh();

        $this->assertSame(BuildingType::Workshop, $game->state->board->hexes[1]->building?->type);
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertSame(6, $game->state->players[0]->resources->coins);
        $this->assertSame(0, $game->actions()->latest('sequence')->first()?->payload['tool_cost']);
        $this->assertSame(0, $game->actions()->latest('sequence')->first()?->payload['coin_cost']);
    }

    public function test_felines_immediately_distribute_a_book_and_three_knowledge_steps_after_founding_a_town(): void
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
                faction: Faction::Felines,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(power: new PowerBowlsStateData(bowlOne: 3)),
                knowledge: new KnowledgeStateData(law: 2),
            )],
            availableTownTileIds: [TownTile::Books->value],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseTown,
                $player->id,
                [TownTile::Books->value],
                ['townHexIds' => ['0:0'], 'builtHexId' => '0:0'],
            ),
        )]);

        $this->actingAs($user)->post(route('games.town', $game), [
            'town_tile' => TownTile::Books->value,
        ])->assertNoContent();
        $game->refresh();

        $this->assertSame(PendingInteractionType::ChooseTownBooks, $game->state->pendingInteraction?->type);
        $this->assertSame(2, $game->state->pendingInteraction?->context['bookCount']);
        $this->assertArrayNotHasKey('knowledgeStepCount', $game->state->pendingInteraction?->context ?? []);

        $this->post(route('games.rewards', $game), [
            'book_counts' => ['banking' => 2, 'law' => 0, 'engineering' => 0, 'medicine' => 0],
        ])->assertNoContent();
        $game->refresh();

        $this->assertSame(PendingInteractionType::ChooseFelineTownBonus, $game->state->pendingInteraction?->type);
        $this->assertSame(1, $game->state->pendingInteraction?->context['bookCount']);
        $this->assertSame(3, $game->state->pendingInteraction?->context['knowledgeStepCount']);
        $this->assertSame(3, $game->state->players[0]->knowledge->unassignedSteps);

        $this->post(route('games.rewards', $game), [
            'book_counts' => ['banking' => 0, 'law' => 0, 'engineering' => 0, 'medicine' => 1],
            'knowledge_counts' => ['banking' => 0, 'law' => 1, 'engineering' => 1, 'medicine' => 0],
        ])->assertSessionHasErrors('knowledge_counts');

        $this->post(route('games.rewards', $game), [
            'book_counts' => ['banking' => 0, 'law' => 0, 'engineering' => 0, 'medicine' => 1],
            'knowledge_counts' => ['banking' => 0, 'law' => 1, 'engineering' => 2, 'medicine' => 0],
        ])->assertNoContent();
        $game->refresh();

        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(2, $game->state->players[0]->resources->books->banking);
        $this->assertSame(1, $game->state->players[0]->resources->books->medicine);
        $this->assertSame(3, $game->state->players[0]->knowledge->law);
        $this->assertSame(2, $game->state->players[0]->knowledge->engineering);
        $this->assertSame(0, $game->state->players[0]->knowledge->unassignedSteps);
        $this->assertSame(2, $game->state->players[0]->resources->power->bowlOne);
        $this->assertSame(1, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame(28, $game->state->players[0]->victoryPoints);
        $this->assertSame(GameActionType::ChooseFelineTownBonus, $game->actions()->latest('sequence')->first()?->type);
        $this->assertSame(3, $game->actions()->latest('sequence')->first()?->payload['victory_points']);
    }

    public function test_felines_finish_the_terraform_town_reward_before_distributing_their_faction_bonus(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id, 'seat' => 1]);
        $neighborUser = User::factory()->create();
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
                    adjacentHexIds: ['1:0', '0:1'],
                    building: new BuildingStateData(BuildingType::Workshop, $player->id),
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    adjacentHexIds: ['0:0', '2:0'],
                ),
                new BoardHexStateData(
                    id: '0:1',
                    q: 0,
                    r: 1,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    adjacentHexIds: ['0:0'],
                ),
                new BoardHexStateData(
                    id: '2:0',
                    q: 2,
                    r: 0,
                    initialTerrain: TerrainType::Swamp,
                    terrain: TerrainType::Swamp,
                    adjacentHexIds: ['1:0'],
                    building: new BuildingStateData(BuildingType::Workshop, $neighbor->id),
                ),
            ]),
            round: new RoundStateData(phase: GamePhase::Actions, turnStartVersion: 0),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Felines,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 1, coins: 2),
            ), new GamePlayerStateData(
                playerId: $neighbor->id,
                userId: $neighborUser->id,
                color: PlayerColor::Red,
                faction: Faction::Blessed,
                homeland: TerrainType::Swamp,
                roundBonus: RoundBonus::PowerCoins,
                resources: new PlayerResourcesData(power: new PowerBowlsStateData(bowlOne: 1)),
            )],
            availableTownTileIds: [TownTile::Terraform->value],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseTown,
                $player->id,
                [TownTile::Terraform->value],
                ['townHexIds' => ['0:0'], 'builtHexId' => '0:0'],
            ),
        )]);

        $this->actingAs($user)->post(route('games.town', $game), [
            'town_tile' => TownTile::Terraform->value,
        ])->assertNoContent();
        $game->refresh();

        $this->assertSame(PendingInteractionType::SpendSpades, $game->state->pendingInteraction?->type);
        $this->assertSame(2, $game->state->players[0]->unassignedSpades);
        $this->assertSame(0, $game->state->players[0]->resources->books->unassigned);

        foreach (['1:0', '0:1'] as $hexId) {
            $this->post(route('games.starting-spade.store', $game), ['hex_id' => $hexId])->assertNoContent();
            $this->post(route('games.starting-spade.finish', $game))->assertNoContent();
        }
        $game->refresh();

        $this->assertSame(PendingInteractionType::BuildWorkshopAfterTerraforming, $game->state->pendingInteraction?->type);
        $this->assertSame(0, $game->state->players[0]->unassignedSpades);
        $this->assertSame(TerrainType::Forest, $game->state->board->hexes[1]->terrain);
        $this->assertSame(TerrainType::Forest, $game->state->board->hexes[2]->terrain);
        $this->assertSame(0, $game->state->players[0]->resources->books->unassigned);

        $this->post(route('games.terraform-workshop', $game), [
            'build' => true,
            'hex_id' => '1:0',
        ])->assertNoContent();
        $game->refresh();

        $this->assertSame(BuildingType::Workshop, $game->state->board->hexes[1]->building?->type);
        $this->assertSame(PendingInteractionType::PowerOffer, $game->state->pendingInteraction?->type);
        $this->assertSame($neighborUser->id, $game->active_player_id);
        $this->assertSame(0, $game->state->players[0]->resources->books->unassigned);

        $this->actingAs($neighborUser)->post(route('games.power-offer', $game), [
            'accept' => false,
        ])->assertNoContent();
        $game->refresh();

        $this->assertSame(PendingInteractionType::ChooseFelineTownBonus, $game->state->pendingInteraction?->type);
        $this->assertSame($user->id, $game->active_player_id);
        $this->assertSame(1, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(3, $game->state->players[0]->knowledge->unassignedSteps);
        $this->actingAs($user);

        $this->post(route('games.rewards', $game), [
            'book_counts' => ['banking' => 1, 'law' => 0, 'engineering' => 0, 'medicine' => 0],
            'knowledge_counts' => ['banking' => 0, 'law' => 1, 'engineering' => 1, 'medicine' => 1],
        ])->assertNoContent();
        $game->refresh();

        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(0, $game->state->players[0]->knowledge->unassignedSteps);
        $this->assertSame(GameActionType::ChooseFelineTownBonus, $game->actions()->latest('sequence')->first()?->type);
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

        $this->post(route('games.current-turn.restart', $decliningGame))->assertNoContent();
        $decliningGame->refresh();
        $this->assertSame(PendingInteractionType::OfferPalaceWaterTown, $decliningGame->state->pendingInteraction?->type);
        $this->assertSame(BuildingType::Workshop, $decliningGame->state->board->hexes[4]->building?->type);
        $this->assertSame(GameActionType::BuildWorkshop, $decliningGame->actions()->latest('sequence')->first()?->type);

        [$acceptingUser, $acceptingGame] = $createGame();
        $this->actingAs($acceptingUser)->post(route('games.workshop', $acceptingGame), ['hex_id' => '4:0']);
        $this->post(route('games.town.palace-water', $acceptingGame), [
            'accept' => true,
            'water_hex_id' => '1:0',
        ]);
        $acceptingGame->refresh();
        $this->assertSame(PendingInteractionType::ChooseTown, $acceptingGame->state->pendingInteraction?->type);
        $this->assertNotNull($acceptingGame->state->turnStartSnapshot);

        $this->post(route('games.current-turn.restart', $acceptingGame))->assertNoContent();
        $acceptingGame->refresh();
        $this->assertSame(PendingInteractionType::OfferPalaceWaterTown, $acceptingGame->state->pendingInteraction?->type);
        $this->assertSame(BuildingType::Workshop, $acceptingGame->state->board->hexes[4]->building?->type);
        $this->assertSame(GameActionType::BuildWorkshop, $acceptingGame->actions()->latest('sequence')->first()?->type);

        $this->post(route('games.town.palace-water', $acceptingGame), [
            'accept' => true,
            'water_hex_id' => '1:0',
        ])->assertNoContent();

        $this->post(route('games.town', $acceptingGame), ['town_tile' => TownTile::Coins->value]);
        $acceptingGame->refresh();
        $waterHex = collect($acceptingGame->state->board->hexes)->firstWhere('id', '1:0');
        $this->assertSame(TownTile::Coins->value, $waterHex?->townTileId);
        $this->assertNotNull($waterHex?->townId);
    }
}
