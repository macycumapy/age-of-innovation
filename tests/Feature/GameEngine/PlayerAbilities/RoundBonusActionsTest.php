<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\PlayerAbilities;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
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
use Tests\TestCase;

class RoundBonusActionsTest extends TestCase
{
    use RefreshDatabase;

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
                    roundBonus: RoundBonus::Knowledge,
                )],
            ),
        ]);

        $this->actingAs($user)->post(route('games.round-bonus-action', $game), [
            'discipline' => KnowledgeDiscipline::Law->value,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->knowledge->law);
        $this->assertSame(21, $game->state->players[0]->victoryPoints);
        $this->assertSame([RoundBonus::Knowledge->value], $game->state->players[0]->usedSpecialActionIds);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame(GameActionType::SpecialAction, $game->actions()->sole()->type);
        $this->assertSame(1, $game->actions()->sole()->payload['victory_points']);

        $this->post(route('games.round-bonus-action', $game), [
            'discipline' => KnowledgeDiscipline::Law->value,
        ])->assertForbidden();

        $this->post(route('games.current-turn.restart', $game))
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->knowledge->law);
        $this->assertSame([], $game->state->players[0]->usedSpecialActionIds);
    }

    public function test_player_cannot_activate_round_bonus_action_after_taking_a_main_action(): void
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
                round: new RoundStateData(phase: GamePhase::Actions, hasTakenMainAction: true),
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

        $this->actingAs($user)
            ->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('game.data.playerBoardStates.0.canUseRoundBonusAction', false)
                    ->where('game.data.playerBoardStates.0.isRoundBonusActionUsed', false),
            );

        $this->post(route('games.round-bonus-action', $game), [
            'discipline' => KnowledgeDiscipline::Law->value,
        ])->assertForbidden();

        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->knowledge->law);
        $this->assertSame([], $game->state->players[0]->usedSpecialActionIds);
        $this->assertCount(0, $game->actions);
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

    public function test_round_bonus_bridge_starts_the_same_bridge_interaction(): void
    {
        [$game, $user] = $this->gameForBridgeAction(RoundBonus::Bridge);

        $this->actingAs($user)->post(route('games.round-bonus-action', $game))
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(PendingInteractionType::PlaceBridge, $game->state->pendingInteraction?->type);
        $this->assertContains(RoundBonus::Bridge->value, $game->state->players[0]->usedSpecialActionIds);

        $this->post(route('games.current-turn.restart', $game))
            ->assertNoContent();
        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame([], $game->state->players[0]->usedSpecialActionIds);
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
}
