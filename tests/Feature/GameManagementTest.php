<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Game\Actions\ApplyIncomeAction;
use App\Domain\Game\Actions\ApplyResourceExchangeAction;
use App\Domain\Game\Actions\CreateBuildingFollowUpInteractionAction;
use App\Domain\Game\Actions\DetermineStartingBuildingOrderAction;
use App\Domain\Game\Actions\ResolveCompletedStartingSetupAction;
use App\Domain\Game\Actions\ResolveIncomePhaseAction;
use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BoardStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\PlanningBundleData;
use App\Domain\Game\Data\PlayerPlanningSelectionData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\PowerBowlsStateData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\FinalRoundScoringTile;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\Innovation;
use App\Domain\Game\Enums\MapVariant;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\PowerAction;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\RoundScoringTile;
use App\Domain\Game\Enums\TerrainType;
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

        $this->assertSame([
            'tools' => 8,
            'coins' => 21,
            'scholars' => 1,
            'power' => 17,
            'books' => 1,
            'knowledgeSteps' => 1,
        ], PlayerIncomeCalculator::calculate($playerState, $board));
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
        $this->assertSame(2, $state->round->incomeTurnIndex);
        $this->assertSame(1, $firstPlayerState->resources->tools);
        $this->assertSame(6, $firstPlayerState->resources->coins);
        $this->assertSame(1, $secondPlayerState->resources->books->unassigned);

        $secondPlayerState->resources->books->unassigned = 0;
        $secondPlayerTools = $secondPlayerState->resources->tools;
        [$activePlayer, $phase] = app(ResolveIncomePhaseAction::class)->execute($state, $players);

        $this->assertSame($firstPlayer->id, $activePlayer->id);
        $this->assertSame(GamePhase::Actions, $phase);
        $this->assertSame(GamePhase::Actions, $state->round->phase);
        $this->assertSame($secondPlayerTools, $secondPlayerState->resources->tools);
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

        $this->post(route('games.starting-spade.store', $game), ['hex_id' => '1:0'])
            ->assertRedirect(route('games.show', $game));
        $game->refresh();
        $this->assertSame(TerrainType::Wasteland, $game->state->board->hexes[1]->terrain);

        $this->delete(route('games.starting-spade.destroy', $game))
            ->assertRedirect(route('games.show', $game));
        $game->refresh();
        $this->assertSame(TerrainType::Desert, $game->state->board->hexes[1]->terrain);

        $this->post(route('games.starting-spade.store', $game), ['hex_id' => '1:0']);
        $this->post(route('games.starting-spade.finish', $game));
        $game->refresh();
        $this->assertSame(1, $game->state->pendingInteraction?->context['remainingSpades']);
        $this->assertSame(1, $game->state->players[0]->unassignedSpades);

        $this->post(route('games.starting-spade.store', $game), ['hex_id' => '1:0']);
        $this->post(route('games.starting-spade.finish', $game));
        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(GamePhase::Actions, $game->phase);
        $this->assertSame(0, $game->state->players[0]->unassignedSpades);
        $this->assertSame(TerrainType::Mountain, $game->state->board->hexes[1]->terrain);
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
        $this->assertCount(7, $game->state->availableTownTileIds);
        $this->assertCount(4, $game->state->availablePalaceIds);
        $this->assertCount(6, $game->state->availableInventionIds);
        $this->assertCount(12, $game->state->availableCompetencyIds);
        $this->assertCount(10, $game->state->roundBonusIds);
        $this->assertEqualsCanonicalizing(
            [$ownerPlayer->id, $secondPlayer->id],
            $game->state->turnOrder,
        );
        $this->assertContains($game->active_player_id, [$owner->id, $secondUser->id]);

        $startAction = $game->actions()->sole();
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
                    ->has('game.data.history.data', 1)
                    ->where('game.data.history.hasMore', false)
                    ->where('game.data.history.data.0.sequence', 1)
                    ->where('game.data.history.data.0.type', GameActionType::StartGame->value)
                    ->where('game.data.history.data.0.player.id', $owner->id)
                    ->where('game.data.history.data.0.player.name', $owner->name)
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
        $this->assertSame(GameActionType::StartGame, $game->actions()->sole()->type);
        $activePlayerId = $game->active_player_id;
        $activeUser = User::query()->findOrFail($activePlayerId);
        $selectedBundle = $game->state->setupPool->planningBundles[0];

        $this->actingAs($activeUser)->post(route('games.planning-bundle.store', $game), [
            'homeland' => $selectedBundle->homeland->value,
        ]);

        $game->refresh();
        $this->assertSame(2, $game->actions()->count());

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
        $this->assertSame(1, $game->actions()->count());
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
                    ->where('game.data.playerBoardStates.0.activeTownKeys', 0)
                    ->where('game.data.playerBoardStates.0.activeAnnexes', 0)
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
    }

    /** @return iterable<string, array{Competency, int, int, int, int}> */
    public static function immediateStartingCompetencyEffects(): iterable
    {
        yield 'competency_04 gives coins, a tool, and victory points' => [
            Competency::Competency04,
            2,
            1,
            5,
            0,
        ];
        yield 'competency_05 gives two unassigned spades' => [
            Competency::Competency05,
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
        $this->assertCount($historyCountBeforeSelection + 1, $game->actions);
        $this->assertSame(GameActionType::SpendStartingSpade, $game->actions()->latest('sequence')->firstOrFail()->type);

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
        $this->assertCount(4, $game->actions);
        $this->assertTrue($game->actions->every(
            static fn (GameAction $action): bool => $action->type === GameActionType::PlaceStartingBuilding,
        ));
        $incomeStartingAction = $game->actions()->latest('sequence')->firstOrFail();
        $this->assertTrue($incomeStartingAction->payload['income_started']);
        $this->assertSame(1, $incomeStartingAction->payload['round']);
        $this->assertSame('income_phase_started', $incomeStartingAction->events[1]['type']);
        $this->assertSame(1, $incomeStartingAction->events[1]['round']);

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
        $this->assertCount(6, $game->actions);
        $this->assertSame(4, $game->state->round->turnStartVersion);
        $this->assertSame($bowlTwoAtTurnStart - 4, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame($bowlThreeAtTurnStart + 2, $game->state->players[0]->resources->power->bowlThree);
        $this->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page->where('game.data.canRestartCurrentTurn', true),
            );

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
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(GamePhase::Income, $game->phase);
        $this->assertContains(Competency::Competency04->value, $monkState->competencyIds);
        $this->assertContains(Competency::Competency04->value, $game->state->availableCompetencyIds);
        $this->assertSame($monkStateBefore->knowledge->medicine + 3, $monkState->knowledge->medicine);
        $monkIncome = PlayerIncomeCalculator::calculate($monkState, $game->state->board);
        $this->assertSame($monkStateBefore->resources->tools + 1 + $monkIncome['tools'], $monkState->resources->tools);
        $this->assertSame($monkStateBefore->resources->coins + 2 + $monkIncome['coins'], $monkState->resources->coins);
        $this->assertSame($monkStateBefore->victoryPoints + 5, $monkState->victoryPoints);
        $this->assertSame(
            GameActionType::ChooseCompetency,
            $game->actions()->latest('sequence')->firstOrFail()->type,
        );
    }
}
