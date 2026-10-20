<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BridgeStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\MapVariant;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Board\Factories\BoardStateFactory;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameSession\Queries\GetGameBoardLayoutQuery;
use App\Mcp\Presentation\GameBoardPresenter;
use App\Mcp\Server\GameServer;
use App\Mcp\Tools\GetGameBoardLayoutTool;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GameBoardLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_id_ignores_dynamic_changes_and_layout_has_no_dynamic_fields(): void
    {
        $board = app(BoardStateFactory::class)->create();
        $presenter = app(GameBoardPresenter::class);
        $layout = $presenter->layout($board);
        $originalDynamic = $presenter->present($board);
        $board->hexes[0]->terrain = TerrainType::Swamp;
        $board->hexes[0]->building = new BuildingStateData(BuildingType::Workshop, 99, true, true);
        $board->hexes[0]->townId = 'town-1';
        $board->hexes[0]->townTileId = 'tile-1';
        $board->bridges = [new BridgeStateData($board->hexes[0]->id, $board->hexes[1]->id, 99)];

        $this->assertSame($layout, $presenter->layout($board));
        $dynamic = $presenter->present($board);
        $this->assertNotSame($originalDynamic, $dynamic);
        $this->assertSame($layout['layout_id'], $dynamic['layout_id']);
        $this->assertSame(['id', 'terrain', 'building', 'townId', 'townTileId'], $dynamic['hex_fields']);
        foreach (['q', 'r', 'initialTerrain', 'adjacentHexIds', 'riverConnectedHexIds'] as $field) {
            $this->assertNotContains($field, $dynamic['hex_fields']);
        }
        foreach (['terrain', 'building', 'townId', 'townTileId'] as $field) {
            $this->assertNotContains($field, $layout['hex_fields']);
        }
        $this->assertArrayNotHasKey('bridges', $layout);
        $this->assertArrayNotHasKey('riverBankHexIds', $dynamic);
        $this->assertArrayNotHasKey('edgeHexIds', $dynamic);
    }

    #[DataProvider('geometryChanges')]
    public function test_layout_id_changes_for_any_actual_geometry_change(string $field): void
    {
        $board = app(BoardStateFactory::class)->create();
        $presenter = app(GameBoardPresenter::class);
        $originalId = $presenter->layout($board)['layout_id'];
        switch ($field) {
            case 'coordinates':
                $board->hexes[0]->q++;
                break;
            case 'id':
                $board->hexes[0]->id = 'custom-hex';
                break;
            case 'initialTerrain':
                $board->hexes[0]->initialTerrain = $board->hexes[0]->initialTerrain === TerrainType::Swamp ? TerrainType::Forest : TerrainType::Swamp;
                break;
            case 'neighbors':
                $board->hexes[0]->adjacentHexIds[] = 'custom-neighbor';
                break;
            case 'riverConnections':
                $board->hexes[0]->riverConnectedHexIds[] = 'custom-river';
                break;
            case 'riverBank':
                $board->riverBankHexIds[] = 'custom-bank';
                break;
            case 'edge':
                $board->edgeHexIds[] = 'custom-edge';
                break;
            case 'variant':
                $board->variant = MapVariant::OneToThreePlayers;
                break;
        }

        $this->assertNotSame($originalId, $presenter->layout($board)['layout_id']);
    }

    public function test_layout_id_is_independent_of_hex_and_neighbor_order(): void
    {
        $board = app(BoardStateFactory::class)->create();
        $presenter = app(GameBoardPresenter::class);
        $layout = $presenter->layout($board);
        $board->hexes = array_reverse($board->hexes);
        foreach ($board->hexes as $hex) {
            $hex->adjacentHexIds = array_reverse($hex->adjacentHexIds);
            $hex->riverConnectedHexIds = array_reverse($hex->riverConnectedHexIds);
        }
        $board->riverBankHexIds = array_reverse($board->riverBankHexIds);
        $board->edgeHexIds = array_reverse($board->edgeHexIds);

        $this->assertSame($layout, $presenter->layout($board));
    }

    public function test_layout_query_is_read_only_and_does_not_read_staged_selection(): void
    {
        [$game, $user] = $this->sessionGame();
        $before = $game->fresh()->getAttributes();
        $historyCount = $game->actions()->count();
        Cache::spy();

        $board = app(GetGameBoardLayoutQuery::class)->execute($user, $game->id);

        $this->assertSame($game->state->board->toArray(), $board->toArray());
        $this->assertSame($before, $game->fresh()->getAttributes());
        $this->assertSame($historyCount, $game->actions()->count());
        Cache::shouldNotHaveReceived('get');
        GameServer::tool(GetGameBoardLayoutTool::class, ['game_id' => $game->id])->assertOk()->assertSee('board-layout-v1');
    }

    public function test_http_state_context_and_wait_all_reference_the_separately_readable_layout(): void
    {
        [$game, $user] = $this->sessionGame();
        $token = $user->createToken('test', ['game:mcp'])->plainTextToken;
        $request = fn (string $name): array => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => ['game_id' => $game->id, 'timeout_seconds' => 0]]];
        $response = $this->withToken($token)->postJson('/mcp/game', $request('get_board_layout'))
            ->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.format', 'board-layout-v1');
        $layoutId = $response->json('result.structuredContent.layout_id');
        $this->assertNotEmpty($layoutId);
        $this->assertSame($response->json('result.structuredContent'), json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR));
        foreach (['get_state', 'get_turn_context', 'wait_for_turn'] as $tool) {
            $this->withToken($token)->postJson('/mcp/game', $request($tool))->assertOk()
                ->assertJsonPath('result.structuredContent.format', 'compact-v2')
                ->assertJsonPath('result.structuredContent.state.board.layout_id', $layoutId)
                ->assertJsonMissingPath('result.structuredContent.state.board.riverBankHexIds');
        }
    }

    public function test_layout_requires_authentication_ability_and_game_membership(): void
    {
        [$game] = $this->sessionGame();
        $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'get_board_layout', 'arguments' => ['game_id' => $game->id]]];
        $this->postJson('/mcp/game', $request)->assertUnauthorized();
        $outsider = User::factory()->create();
        $token = $outsider->createToken('test', ['profile:read'])->plainTextToken;
        $this->withToken($token)->postJson('/mcp/game', $request)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $token = $outsider->createToken('game', ['game:mcp'])->plainTextToken;
        $this->withToken($token)->postJson('/mcp/game', $request)->assertOk()->assertJsonPath('result.isError', true);
    }

    public function test_layout_requires_game_id_when_ambiguous_and_rejects_invalid_ids(): void
    {
        [$game, $user] = $this->sessionGame();
        GamePlayer::factory()->create(['user_id' => $user->id]);
        GameServer::tool(GetGameBoardLayoutTool::class, [])->assertHasErrors()->assertSee('Specify game_id');
        GameServer::tool(GetGameBoardLayoutTool::class, ['game_id' => $game->id])->assertOk();
        GameServer::tool(GetGameBoardLayoutTool::class, ['game_id' => 0])->assertHasErrors();
    }

    /** @return list<array{string}> */
    public static function geometryChanges(): array
    {
        return array_map(fn (string $field): array => [$field], ['coordinates', 'id', 'initialTerrain', 'neighbors', 'riverConnections', 'riverBank', 'edge', 'variant']);
    }

    /** @return array{Game, User} */
    private function sessionGame(): array
    {
        $user = User::factory()->create();
        $game = Game::factory()->create(['state' => new GameStateData(board: app(BoardStateFactory::class)->create()), 'status' => GameStatus::Active]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $game->update(['active_game_player_id' => $player->id]);
        config(['game-mcp.user_id' => $user->id]);

        return [$game, $user];
    }
}
