<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Interactions;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Data\PlaceBridgeOptionData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Actions\PerformPowerActionAction;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Economy\Enums\PowerAction;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Interactions\Actions\PerformGameActionOptionAction;
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

class PerformGameActionOptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_game_action_option_performer_stages_and_confirms_a_bridge_atomically(): void
    {
        [$game, $user] = $this->gameForBridgeAction();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $game = app(PerformPowerActionAction::class)->execute($game, $player, PowerAction::BuildBridge, 0);
        $state = $game->state;
        $interaction = $state->pendingInteraction;
        $this->assertNotNull($interaction);
        $interaction->context['pairs'] = [[
            'fromHexId' => '8:5',
            'toHexId' => '6:7',
        ], [
            'fromHexId' => '8:5',
            'toHexId' => '8:7',
        ]];
        $game->update(['state' => $state]);

        app(PerformGameActionOptionAction::class)->execute(
            $game,
            $game->players()->whereBelongsTo($user)->firstOrFail(),
            new PlaceBridgeOptionData('8:5', '7:7'),
        );

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
