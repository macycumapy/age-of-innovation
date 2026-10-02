<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Board;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnexPlacementTest extends TestCase
{
    use RefreshDatabase;

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
}
