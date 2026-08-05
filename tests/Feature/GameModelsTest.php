<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\TerrainType;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class GameModelsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_game_stores_state_and_resolves_its_relationships(): void
    {
        $activePlayer = User::factory()->create();
        $game = Game::factory()->create([
            'active_player_id' => $activePlayer->id,
            'state' => ['schemaVersion' => 1, 'round' => ['number' => 1]],
        ]);
        $gamePlayer = GamePlayer::factory()->recycle($game)->recycle($activePlayer)->create([
            'seat' => 1,
            'color' => PlayerColor::Yellow,
            'faction' => Faction::Inventors,
            'homeland' => TerrainType::Desert,
        ]);
        $action = GameAction::factory()->recycle($game)->create([
            'player_id' => $activePlayer->id,
            'sequence' => 1,
        ]);

        $this->assertInstanceOf(GameStateData::class, $game->state);
        $this->assertSame(1, $game->state->schemaVersion);
        $this->assertSame(GameStatus::Lobby, $game->status);
        $this->assertSame(GamePhase::Setup, $game->phase);
        $this->assertTrue($game->activePlayer->is($activePlayer));
        $this->assertTrue($game->players->contains($gamePlayer));
        $this->assertTrue($game->actions->contains($action));
        $this->assertTrue($gamePlayer->user->is($activePlayer));
        $this->assertSame(PlayerColor::Yellow, $gamePlayer->color);
        $this->assertSame(Faction::Inventors, $gamePlayer->faction);
        $this->assertSame(TerrainType::Desert, $gamePlayer->homeland);
        $this->assertTrue($action->player->is($activePlayer));
        $this->assertSame(GameActionType::Pass, $action->type);
    }

    public function test_game_action_casts_payload_and_events_to_arrays(): void
    {
        $action = GameAction::factory()->create([
            'payload' => ['hexId' => 'D7'],
            'events' => [['type' => 'building_built']],
        ]);

        $this->assertSame(['hexId' => 'D7'], $action->payload);
        $this->assertSame([['type' => 'building_built']], $action->events);
    }

    public function test_a_user_cannot_join_the_same_game_twice(): void
    {
        $game = Game::factory()->create();
        $user = User::factory()->create();

        GamePlayer::factory()->recycle($game)->recycle($user)->create(['seat' => 1]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        GamePlayer::factory()->recycle($game)->recycle($user)->create(['seat' => 2]);
    }
}
