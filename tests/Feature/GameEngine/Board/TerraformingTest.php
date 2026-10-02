<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Board;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Actions\FindEligibleTerraformHexesAction;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BridgeStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Economy\Enums\PowerAction;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TerraformingTest extends TestCase
{
    use RefreshDatabase;

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
            round: new RoundStateData(
                phase: GamePhase::Actions,
                scoringTileId: RoundScoringTile::SpadeEngineering->value,
            ),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Grey,
                faction: Faction::Goblins,
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
        ])->assertNoContent();
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
        $this->assertSame(4, $paidTerraformingAction->payload['bonus_coins']);
        $this->assertSame(4, $game->state->players[0]->resources->coins);
        $this->assertSame(4, $paidTerraformingAction->payload['victory_points']);
        $this->assertSame(24, $game->state->players[0]->victoryPoints);
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

    public function test_moles_can_use_a_tunnel_for_paid_terraforming(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id, 'seat' => 1]);
        $otherPlayer = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $otherUser->id, 'seat' => 2]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id, $otherPlayer->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '7:3',
                    q: 7,
                    r: 3,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    adjacentHexIds: ['7:4'],
                    building: new BuildingStateData(BuildingType::Workshop, $player->id),
                ),
                new BoardHexStateData(
                    id: '7:4',
                    q: 7,
                    r: 4,
                    initialTerrain: TerrainType::Water,
                    terrain: TerrainType::Water,
                    adjacentHexIds: ['7:3', '6:5'],
                ),
                new BoardHexStateData(
                    id: '6:5',
                    q: 6,
                    r: 5,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['7:4'],
                ),
            ]),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [
                new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Grey,
                    faction: Faction::Moles,
                    homeland: TerrainType::Mountain,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        tools: 5,
                        power: new PowerBowlsStateData(bowlThree: 4),
                    ),
                    victoryPoints: 20,
                    shippingLevel: 1,
                ),
                new GamePlayerStateData(
                    playerId: $otherPlayer->id,
                    userId: $otherUser->id,
                    color: PlayerColor::Blue,
                    faction: Faction::Navigators,
                    homeland: TerrainType::Lake,
                    roundBonus: RoundBonus::Coins,
                ),
            ],
        )]);

        $this->actingAs($user)->post(route('games.power-action', $game), [
            'action' => PowerAction::TerraformOneSpade->value,
            'sacrifice_amount' => 0,
        ])->assertNoContent();

        $this->post(route('games.paid-terraforming', $game), [
            'hex_id' => '6:5',
            'use_tunnel' => true,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(4, $game->state->players[0]->resources->tools);
        $this->assertSame(24, $game->state->players[0]->victoryPoints);
        $this->assertSame(TerrainType::Mountain, $game->state->board->hexes[2]->terrain);

        $this->delete(route('games.starting-spade.destroy', $game))->assertNoContent();
        $game->refresh();
        $this->assertSame(5, $game->state->players[0]->resources->tools);
        $this->assertSame(20, $game->state->players[0]->victoryPoints);
        $this->assertSame(TerrainType::Forest, $game->state->board->hexes[2]->terrain);

        $this->post(route('games.paid-terraforming', $game), [
            'hex_id' => '6:5',
            'use_tunnel' => true,
        ])->assertNoContent();

        $this->post(route('games.starting-spade.finish', $game))->assertNoContent();
        $game->refresh();
        $action = $game->actions()->where('type', GameActionType::SpendStartingSpade)->sole();
        $this->assertSame(1, $action->payload['tunnel_tools']);
        $this->assertSame(4, $action->payload['tunnel_victory_points']);

        $this->post(route('games.current-turn.restart', $game))->assertNoContent();
        $game->refresh();
        $this->assertSame(5, $game->state->players[0]->resources->tools);
        $this->assertSame(20, $game->state->players[0]->victoryPoints);
        $this->assertSame(TerrainType::Forest, $game->state->board->hexes[2]->terrain);
    }

    public function test_moles_can_confirm_two_tunnels_on_different_hexes_and_rollback_the_second_selection(): void
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
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    adjacentHexIds: ['1:0', '0:1'],
                    building: new BuildingStateData(BuildingType::Workshop, $player->id),
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
                    id: '0:1',
                    q: 0,
                    r: 1,
                    initialTerrain: TerrainType::Water,
                    terrain: TerrainType::Water,
                    adjacentHexIds: ['0:0', '0:2'],
                ),
                new BoardHexStateData(
                    id: '2:0',
                    q: 2,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['1:0'],
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
                color: PlayerColor::Grey,
                faction: Faction::Moles,
                homeland: TerrainType::Mountain,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 2),
                victoryPoints: 20,
                unassignedSpades: 2,
            )],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::SpendSpades,
                $player->id,
                ['2:0', '0:2'],
                ['remainingSpades' => 2, 'targetTerrain' => TerrainType::Mountain->value],
            ),
        )]);

        $this->actingAs($user)->post(route('games.paid-terraforming', $game), [
            'hex_id' => '2:0', 'use_tunnel' => true,
        ])->assertNoContent();
        $this->assertSame(0, $game->actions()->count());
        $this->post(route('games.starting-spade.finish', $game))->assertNoContent();
        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->unassignedSpades);
        $this->assertContains('0:2', $game->state->pendingInteraction->optionIds);
        $beforeSecondSelection = $game->state->toArray();

        $this->post(route('games.paid-terraforming', $game), [
            'hex_id' => '0:2', 'use_tunnel' => true,
        ])->assertNoContent();
        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertSame(26, $game->state->players[0]->victoryPoints);
        $this->assertSame(1, $game->actions()->count());

        $this->delete(route('games.starting-spade.destroy', $game))->assertNoContent();
        $game->refresh();
        $this->assertSame($beforeSecondSelection, $game->state->toArray());
        $this->assertSame(1, $game->actions()->count());

        $this->post(route('games.paid-terraforming', $game), [
            'hex_id' => '0:2', 'use_tunnel' => true,
        ])->assertNoContent();
        $this->post(route('games.starting-spade.finish', $game))->assertNoContent();
        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->unassignedSpades);
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertSame(26, $game->state->players[0]->victoryPoints);
        $this->assertSame(TerrainType::Mountain, $game->state->board->hexes[3]->terrain);
        $this->assertSame(TerrainType::Mountain, $game->state->board->hexes[4]->terrain);
        $actions = $game->actions()->orderBy('sequence')->get();
        $this->assertCount(2, $actions);
        $this->assertSame(['2:0', '0:2'], $actions->pluck('payload.hex_id')->all());
        $this->assertSame([1, 1], $actions->pluck('payload.tunnel_tools')->all());
        $this->assertSame([3, 3], $actions->pluck('payload.tunnel_victory_points')->all());
    }

    public function test_palace_nine_flight_can_be_selected_rolled_back_and_confirmed(): void
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
                    building: new BuildingStateData(BuildingType::Workshop, $player->id),
                ),
                new BoardHexStateData(
                    id: '3:0',
                    q: 3,
                    r: 0,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                ),
                new BoardHexStateData(
                    id: '4:0',
                    q: 4,
                    r: 0,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                ),
                new BoardHexStateData(
                    id: '0:2',
                    q: 0,
                    r: 2,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['0:3'],
                    building: new BuildingStateData(BuildingType::Workshop, $player->id),
                ),
                new BoardHexStateData(
                    id: '0:3',
                    q: 0,
                    r: 3,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    adjacentHexIds: ['0:2'],
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
                resources: new PlayerResourcesData(tools: 3, scholars: 1),
                palaceId: PalaceAbility::Palace09->value,
            )],
        )]);

        $this->actingAs($user)->post(route('games.paid-terraforming', $game), [
            'hex_id' => '4:0',
            'use_flight' => true,
        ])->assertSessionHasErrors('hex_id');

        $this->post(route('games.paid-terraforming', $game), [
            'hex_id' => '0:3',
            'use_flight' => true,
        ])->assertSessionHasErrors('hex_id');

        $this->post(route('games.paid-terraforming', $game), [
            'hex_id' => '3:0',
        ])->assertSessionHasErrors('hex_id');

        $this->post(route('games.paid-terraforming', $game), [
            'hex_id' => '3:0',
            'use_flight' => true,
        ])->assertNoContent();
        $game->refresh();

        $this->assertSame(0, $game->state->players[0]->resources->scholars);
        $this->assertSame(25, $game->state->players[0]->victoryPoints);
        $this->assertSame(TerrainType::Forest, $game->state->board->hexes[1]->terrain);

        $this->delete(route('games.starting-spade.destroy', $game))->assertNoContent();
        $game->refresh();

        $this->assertSame(1, $game->state->players[0]->resources->scholars);
        $this->assertSame(20, $game->state->players[0]->victoryPoints);
        $this->assertSame(TerrainType::Mountain, $game->state->board->hexes[1]->terrain);

        $this->post(route('games.paid-terraforming', $game), [
            'hex_id' => '3:0',
            'use_flight' => true,
        ])->assertNoContent();
        $this->post(route('games.starting-spade.finish', $game))->assertNoContent();
        $game->refresh();

        $action = $game->actions()->where('type', GameActionType::SpendStartingSpade)->sole();
        $this->assertSame(1, $action->payload['flight_scholar_cost']);
        $this->assertSame(5, $action->payload['flight_victory_points']);

        $this->post(route('games.current-turn.restart', $game))->assertNoContent();
        $game->refresh();

        $this->assertSame(1, $game->state->players[0]->resources->scholars);
        $this->assertSame(20, $game->state->players[0]->victoryPoints);
        $this->assertSame(TerrainType::Mountain, $game->state->board->hexes[1]->terrain);
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
        ])->assertNoContent();

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
            ->assertNoContent();
        $game->refresh();
        $this->assertSame(TerrainType::Mountain, $game->state->board->hexes[1]->terrain);
        $this->assertSame(0, $game->state->players[0]->resources->tools);

        $this->delete(route('games.starting-spade.destroy', $game))
            ->assertNoContent();
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
                resources: new PlayerResourcesData(
                    power: new PowerBowlsStateData(bowlTwo: 2, bowlThree: 4),
                ),
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

        $this->post(route('games.power-sacrifice.store', $game), ['amount' => 1])
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(PendingInteractionType::BuildWorkshopAfterTerraforming, $game->state->pendingInteraction?->type);
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame(5, $game->state->players[0]->resources->power->bowlThree);

        $this->post(route('games.resource-exchange', $game), [
            'exchanges' => $this->resourceExchanges(powerToTool: 1, powerToCoin: 2),
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(PendingInteractionType::BuildWorkshopAfterTerraforming, $game->state->pendingInteraction?->type);
        $this->assertSame(1, $game->state->players[0]->resources->tools);
        $this->assertSame(2, $game->state->players[0]->resources->coins);

        $this->post(route('games.terraform-workshop', $game), ['build' => true, 'hex_id' => '1:0'])
            ->assertNoContent();
        $game->refresh();
        $this->assertSame(BuildingType::Workshop, $game->state->board->hexes[0]->building?->type);
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertSame(0, $game->state->players[0]->resources->coins);
        $this->assertNull($game->state->pendingInteraction);

        $this->post(route('games.building-upgrade', $game), [
            'hex_id' => '1:0',
            'target' => BuildingType::Guild->value,
        ])->assertForbidden();
        $this->post(route('games.power-action', $game), [
            'action' => PowerAction::GainCoins->value,
            'sacrifice_amount' => 0,
        ])->assertForbidden();

        $this->post(route('games.current-turn.finish', $game))
            ->assertNoContent();
        $game->refresh();
        $this->assertSame($nextUser->id, $game->active_player_id);
        $this->assertNull($game->state->round->turnStartVersion);
        $this->assertSame(GameActionType::FinishTurn, $game->actions()->latest('sequence')->first()?->type);
    }

    public function test_player_can_decline_workshop_after_power_terraforming(): void
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
            round: new RoundStateData(
                phase: GamePhase::Actions,
                turnStartVersion: 0,
                hasTakenMainAction: true,
            ),
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

        $this->actingAs($user)->get(route('games.show', $game))
            ->assertInertia(fn (Assert $page) => $page->where('game.data.canFinishCurrentTurn', true));
        $this->post(route('games.current-turn.finish', $game))->assertNoContent();
        $game->refresh();

        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(1, $game->state->players[0]->resources->tools);
        $this->assertSame(2, $game->state->players[0]->resources->coins);
        $this->assertSame($nextUser->id, $game->active_player_id);
        $this->assertSame(GameActionType::FinishTurn, $game->actions()->latest('sequence')->first()?->type);
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
}
