<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Research;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\BookSupplyData;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\Setup\Factories\GameSetupPoolFactory;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Towns\Enums\TownTile;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InnovationPurchaseTest extends TestCase
{
    use RefreshDatabase;

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
        ])->assertNoContent();

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

    public function test_architecture_innovation_grants_a_knowledge_step_for_each_owned_building_type(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'architecture-knowledge');
        $setupPool->innovations[0] = Innovation::Architecture;
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::Workshop, $player->id),
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::Guild, $player->id),
                ),
                new BoardHexStateData(
                    id: '2:0',
                    q: 2,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::Tower, $player->id, isNeutral: true),
                ),
                new BoardHexStateData(
                    id: '3:0',
                    q: 3,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::Monument, $player->id + 1, isNeutral: true),
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
                    coins: 10,
                    books: new BookSupplyData(banking: 2, law: 2, medicine: 1),
                ),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            availableInventionIds: [Innovation::Architecture->value],
            setupPool: $setupPool,
        )]);

        $this->actingAs($user)->post(route('games.innovation', $game), [
            'innovation' => Innovation::Architecture->value,
            'book_counts' => ['banking' => 2, 'law' => 2, 'engineering' => 0, 'medicine' => 1],
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(PendingInteractionType::ChooseInnovationReward, $game->state->pendingInteraction?->type);
        $this->assertSame(3, $game->state->pendingInteraction?->context['knowledgeStepCount']);
        $this->assertSame(3, $game->state->players[0]->knowledge->unassignedSteps);

        $this->post(route('games.rewards', $game), [
            'book_counts' => ['banking' => 0, 'law' => 0, 'engineering' => 0, 'medicine' => 0],
            'knowledge_counts' => ['banking' => 2, 'law' => 1, 'engineering' => 0, 'medicine' => 0],
        ])->assertNoContent();

        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(2, $game->state->players[0]->knowledge->banking);
        $this->assertSame(1, $game->state->players[0]->knowledge->law);
        $this->assertSame(0, $game->state->players[0]->knowledge->unassignedSteps);
        $this->assertEquals(
            ['banking' => 2, 'law' => 1, 'engineering' => 0, 'medicine' => 0],
            $game->actions()->sole()->payload['reward_knowledge_counts'],
        );
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
        ?PalaceAbility $palace = null,
        bool $skip = false,
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
                    tools: $skip ? 0 : 3,
                    coins: 10,
                    books: new BookSupplyData(banking: 2, law: 2, medicine: 1),
                ),
                palaceId: $palace?->value,
            )],
            round: new RoundStateData(
                phase: GamePhase::Actions,
                scoringTileId: RoundScoringTile::WorkshopLaw->value,
            ),
            availableInventionIds: [Innovation::Workshop->value],
            setupPool: $setupPool,
        )]);

        $this->actingAs($user)->post(route('games.innovation', $game), [
            'innovation' => Innovation::Workshop->value,
            'book_counts' => ['banking' => 2, 'law' => 2, 'engineering' => 0, 'medicine' => 1],
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(PendingInteractionType::PlaceNeutralBuilding, $game->state->pendingInteraction?->type);
        $this->assertSame($skip ? [] : ['1:0'], $game->state->pendingInteraction?->optionIds);

        if ($skip) {
            $this->post(route('games.innovation.neutral-building', $game), ['hex_id' => '1:0'])
                ->assertSessionHasErrors('hex_id');
            $this->assertSame(PendingInteractionType::PlaceNeutralBuilding, $game->fresh()->state->pendingInteraction?->type);

            $this->post(route('games.innovation.neutral-building', $game), ['skip' => 1])->assertNoContent();
            $game->refresh();
            $this->assertNull($game->state->pendingInteraction);
            $this->assertNull($game->state->board->hexes[1]->building);
            $this->assertSame(TerrainType::Mountain, $game->state->board->hexes[1]->terrain);
            $this->assertSame(0, $game->state->players[0]->resources->tools);
            $this->assertSame(20, $game->state->players[0]->victoryPoints);
            $this->assertTrue($game->actions()->sole()->payload['neutral_building']['skipped']);
            $this->assertSame(0, $game->actions()->sole()->payload['neutral_building']['victory_points']);

            $game->actions()->create([
                'sequence' => 0,
                'type' => GameActionType::PhaseCheckpoint,
                'payload' => [
                    'game' => [
                        'status' => GameStatus::Active->value,
                        'round' => 1,
                        'phase' => GamePhase::Actions->value,
                        'active_player_id' => $user->id,
                        'active_game_player_id' => $player->id,
                        'version' => 0,
                        'state' => $game->state->turnStartSnapshot,
                        'started_at' => null,
                        'finished_at' => null,
                    ],
                    'players' => [['id' => $player->id]],
                ],
                'events' => [],
                'state_version_before' => 0,
                'state_version_after' => 0,
            ]);
            app(\App\Domain\GameEngine\History\Actions\ReplayGameHistoryAction::class)->execute($game, $game->actions()->orderBy('sequence')->get());
            $game->refresh();
            $this->assertNull($game->state->pendingInteraction);
            $this->assertNull($game->state->board->hexes[1]->building);
            $this->assertSame(0, $game->state->players[0]->resources->tools);

            $this->post(route('games.current-turn.restart', $game))->assertNoContent();
            $game->refresh();
            $this->assertSame([], $game->state->players[0]->inventionIds);
            $this->assertSame(0, $game->actions()->count());

            return;
        }

        $this->post(route('games.innovation.neutral-building', $game), ['hex_id' => '1:0'])
            ->assertNoContent();

        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(3 - $expectedToolCost, $game->state->players[0]->resources->tools);
        $this->assertSame(TerrainType::Forest, $game->state->board->hexes[1]->terrain);
        $this->assertSame(BuildingType::Workshop, $game->state->board->hexes[1]->building?->type);
        $this->assertTrue($game->state->board->hexes[1]->building?->isNeutral);
        $expectedVictoryPoints = $palace === PalaceAbility::Palace12 ? 4 : 2;
        $this->assertSame(20 + $expectedVictoryPoints, $game->state->players[0]->victoryPoints);
        $this->assertSame($expectedVictoryPoints, $game->actions()->sole()->payload['neutral_building']['victory_points']);
        if ($palace === PalaceAbility::Palace12) {
            $this->assertEquals([
                'source' => 'palace',
                'id' => PalaceAbility::Palace12->value,
                'points' => 2,
            ], collect($game->actions()->sole()->payload['neutral_building']['scoring_sources'])->firstWhere('source', 'palace'));
        }
        $this->assertSame('1:0', $game->actions()->sole()->payload['neutral_building']['hex_id']);
        $this->assertSame($expectedToolCost, $game->actions()->sole()->payload['neutral_building']['tools']);
    }

    /** @return array<string, array{TerrainType, int, PalaceAbility|null, bool}> */
    public static function neutralInnovationBuildingTerrainProvider(): array
    {
        return [
            'without terraforming' => [TerrainType::Forest, 0, null, false],
            'with terraforming' => [TerrainType::Mountain, 3, null, false],
            'palace twelve without terraforming' => [TerrainType::Forest, 0, PalaceAbility::Palace12, false],
            'palace twelve with terraforming' => [TerrainType::Mountain, 3, PalaceAbility::Palace12, false],
            'explicit refusal without tools' => [TerrainType::Mountain, 3, null, true],
        ];
    }

    public function test_neutral_guild_from_innovation_scores_the_build_guild_round_bonus(): void
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
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'neutral-guild-round-bonus');
        $setupPool->innovations[0] = Innovation::Guild;
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
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['0:0'],
                ),
            ]),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::BuildGuild,
                resources: new PlayerResourcesData(
                    tools: 3,
                    coins: 10,
                    books: new BookSupplyData(banking: 2, law: 2, medicine: 1),
                ),
            )],
            round: new RoundStateData(
                phase: GamePhase::Actions,
                scoringTileId: RoundScoringTile::WorkshopLaw->value,
            ),
            availableInventionIds: [Innovation::Guild->value],
            setupPool: $setupPool,
        )]);

        $this->actingAs($user)->post(route('games.innovation', $game), [
            'innovation' => Innovation::Guild->value,
            'book_counts' => ['banking' => 2, 'law' => 2, 'engineering' => 0, 'medicine' => 1],
        ])->assertNoContent();

        $this->post(route('games.innovation.neutral-building', $game), ['hex_id' => '1:0'])
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(BuildingType::Guild, $game->state->board->hexes[1]->building?->type);
        $this->assertSame(23, $game->state->players[0]->victoryPoints);
        $this->assertSame(3, $game->actions()->sole()->payload['neutral_building']['victory_points']);
        $this->assertSame([
            [
                'id' => RoundBonus::BuildGuild->value,
                'points' => 3,
                'source' => 'round_bonus',
            ],
        ], $game->actions()->sole()->payload['neutral_building']['scoring_sources']);
        $this->get(route('games.show', $game))->assertInertia(
            fn (Assert $page) => $page
                ->where('game.data.history.data.0.payload.neutral_building.victory_points', 3)
                ->where(
                    'game.data.history.data.0.payload.neutral_building.scoring_sources.0.source',
                    'round_bonus',
                ),
        );
    }

    public function test_school_innovation_grants_a_competency_when_no_neutral_school_can_be_built(): void
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
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'school-innovation-without-building');
        $setupPool->innovations[0] = Innovation::School;
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::Workshop, $player->id),
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
                    coins: 10,
                    books: new BookSupplyData(banking: 2, law: 2, medicine: 1),
                ),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            availableInventionIds: [Innovation::School->value],
            availableCompetencyIds: [Competency::Competency04->value],
            setupPool: $setupPool,
        )]);

        $this->actingAs($user)->post(route('games.innovation', $game), [
            'innovation' => Innovation::School->value,
            'book_counts' => ['banking' => 2, 'law' => 2, 'engineering' => 0, 'medicine' => 1],
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(PendingInteractionType::ChooseCompetency, $game->state->pendingInteraction?->type);
        $this->assertSame([Competency::Competency04->value], $game->state->pendingInteraction?->optionIds);
        $this->assertSame('innovation', $game->state->pendingInteraction?->context['reason']);
        $this->assertFalse($game->state->board->hexes[0]->building?->isNeutral);

        $this->post(route('games.rewards', $game), [
            'competency_id' => Competency::Competency04->value,
        ])->assertNoContent();

        $game->refresh();
        $this->assertContains(Competency::Competency04->value, $game->state->players[0]->competencyIds);
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame('innovation', $game->actions()->latest('sequence')->first()?->payload['reason']);
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
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(PendingInteractionType::ChooseInnovationReward, $game->state->pendingInteraction?->type);
        $this->assertSame(2, $game->state->pendingInteraction?->context['bookCount']);
        $this->assertSame('innovation', $game->state->pendingInteraction?->context['source']);
        $this->assertSame(2, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(1, $game->actions()->count());

        $this->actingAs($user)->post(route('games.rewards', $game), [
            'book_counts' => ['banking' => 1, 'law' => 0, 'engineering' => 0, 'medicine' => 0],
        ])->assertSessionHasErrors('book_counts');

        $game->refresh();
        $this->assertSame(PendingInteractionType::ChooseInnovationReward, $game->state->pendingInteraction?->type);
        $this->assertSame(2, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(1, $game->actions()->count());

        $this->actingAs($user)->post(route('games.rewards', $game), [
            'book_counts' => ['banking' => 1, 'law' => 0, 'engineering' => 1, 'medicine' => 0],
        ])->assertNoContent();

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
}
