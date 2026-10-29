<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Interactions\Services\GameActionOptionFinder;
use App\Domain\GameEngine\Setup\Data\PlanningBundleOptionData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Domain\GameSession\Actions\RollbackGameAction;
use App\Domain\GameSession\Actions\StageGameAction;
use App\Domain\GameSession\Data\GameActionOptionsData;
use App\Domain\GameSession\Data\GameStateViewData;
use App\Domain\GameSession\Data\StagedGameActionData;
use App\Domain\GameSession\Queries\GetGameStateQuery;
use App\Domain\GameSession\Queries\GetLegalGameActionsQuery;
use App\Events\GameChanged;
use App\Events\GamePlayersChanged;
use App\Mcp\Presentation\GameActionIdentifier;
use App\Mcp\Presentation\GameActionPresenter;
use App\Mcp\Presentation\GameStatePresenter;
use App\Mcp\Server\GameServer;
use App\Mcp\Tools\ExecuteGameActionTool;
use App\Mcp\Tools\GetGameBoardLayoutTool;
use App\Mcp\Tools\GetGameComponentTool;
use App\Mcp\Tools\GetGameStateTool;
use App\Mcp\Tools\GetGameTurnContextTool;
use App\Mcp\Tools\GetLegalGameActionsTool;
use App\Mcp\Tools\JoinGameTool;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Registrar;
use Tests\TestCase;

class GameServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_transport_formats_typed_options_and_staged_selection_with_stable_identifiers(): void
    {
        $option = new PlanningBundleOptionData(TerrainType::Forest);
        $result = new GameActionOptionsData(3, [$option, $option], true);
        $responses = app(GameActionPresenter::class);
        $identifier = app(GameActionIdentifier::class);
        $payload = $responses->legalActions($result);

        $this->assertCount(2, $payload['actions']);
        $this->assertSame(3, $payload['version']);
        $id = $payload['actions'][0]['action_id'];
        $this->assertEquals($option, $identifier->resolve($id, $result));
        $this->assertNull($identifier->resolve('finish_turn', $result));
        $this->assertSame('finish_turn', $payload['actions'][1]['action_id']);
        $this->assertSame(GameActionOptionType::FinishTurn, GameActionOptionType::from($payload['actions'][1]['type']));
        $this->assertSame(
            ['version' => 3, 'action_id' => $id],
            $responses->staged(new StagedGameActionData(3, $option)),
        );
    }

    public function test_transport_hides_internal_snapshots_without_modifying_the_domain_result(): void
    {
        $state = new GameStateData(turnStartSnapshot: ['private' => 'snapshot'], townChoiceCheckpoint: ['private' => 'checkpoint']);
        $view = new GameStateViewData(1, 2, 3, 4, 3, $state, null);

        $payload = app(GameStatePresenter::class)->present($view);

        $this->assertArrayNotHasKey('turnStartSnapshot', $payload['state']);
        $this->assertArrayNotHasKey('townChoiceCheckpoint', $payload['state']);
        $this->assertSame(['private' => 'snapshot'], $view->state->turnStartSnapshot);
        $this->assertSame(['private' => 'checkpoint'], $view->state->townChoiceCheckpoint);
    }

    public function test_each_tool_exposes_its_own_schema_and_read_only_metadata(): void
    {
        $tools = [
            JoinGameTool::class => ['join_game', ['game_id']],
            GetGameStateTool::class => ['get_state', []],
            GetGameTurnContextTool::class => ['get_turn_context', []],
            GetGameBoardLayoutTool::class => ['get_board_layout', []],
            GetGameComponentTool::class => ['get_component', ['type', 'id']],
            GetLegalGameActionsTool::class => ['get_legal_actions', []],
            ExecuteGameActionTool::class => ['execute_action', ['version', 'action_id']],
        ];
        foreach ($tools as $class => [$name, $required]) {
            $definition = app($class)->toArray();
            $this->assertSame($name, $definition['name']);
            $this->assertArrayNotHasKey('operation', $definition['inputSchema']['properties']);
            $this->assertEqualsCanonicalizing($required, $definition['inputSchema']['required'] ?? []);
            $annotations = (array) $definition['annotations'];
            $this->assertSame(in_array($name, ['get_state', 'get_legal_actions', 'get_turn_context', 'get_board_layout', 'get_component'], true), $annotations['readOnlyHint'] ?? false);
        }
    }

    public function test_mutation_tools_require_their_own_parameters(): void
    {
        $this->createGameSession();
        foreach ([[], ['version' => 0], ['version' => -1, 'action_id' => 'finish_turn'], ['action_id' => 'finish_turn']] as $arguments) {
            GameServer::tool(ExecuteGameActionTool::class, $arguments)->assertHasErrors();
        }
    }

    public function test_server_context_contains_the_game_instructions(): void
    {
        $server = new GameServer($this->createMock(Transport::class));
        $instructions = $server->createContext()->instructions;
        $this->assertStringContainsString('Use join_game with game_id', $instructions);
        $this->assertStringContainsString('GAME_MCP_USER_ID', $instructions);
        $this->assertStringContainsString('Use execute_action', $instructions);
        $this->assertStringContainsString('Use the current version for action mutations.', $instructions);
        $this->assertStringContainsString('specify game_id on each call.', $instructions);
        foreach (['stage_action', 'confirm_action', 'rollback_action'] as $obsolete) {
            $this->assertStringNotContainsString($obsolete, $instructions);
        }
    }

    public function test_server_is_registered_and_requires_a_configured_user(): void
    {
        $this->assertNotNull(app(Registrar::class)->getLocalServer('game'));
        config(['game-mcp.user_id' => null]);
        GameServer::tool(GetGameStateTool::class, [])
            ->assertHasErrors()->assertSee('GAME_MCP_USER_ID');
    }

    public function test_selection_and_rollback_preserve_the_complete_game_and_history(): void
    {
        [$game, $player, $actionId] = $this->createGameSession();
        $before = $game->fresh()->getAttributes();
        Event::fake([GameChanged::class]);
        GameServer::tool(GetGameStateTool::class, [])->assertOk()->assertDontSee('strategicPlan');
        GameServer::tool(GetLegalGameActionsTool::class, [])->assertOk()->assertSee($actionId);
        $this->stageSelection($game, $player, $actionId);
        $this->assertSame($before, $game->fresh()->getAttributes());
        app(RollbackGameAction::class)->execute($player->user, $game->id, $game->version);
        $this->assertSame($before, $game->fresh()->getAttributes());
        $this->assertNull(Cache::get('game:staged-action:'.$player->id));
        $this->assertSame(0, $game->actions()->count());
        Event::assertNotDispatched(GameChanged::class);
    }

    public function test_configured_user_can_join_a_lobby_and_read_its_state(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->create(['game_id' => $game->id, 'seat' => 1]);
        Event::fake([GamePlayersChanged::class, GameChanged::class]);
        config(['game-mcp.user_id' => $user->id]);

        GameServer::tool(JoinGameTool::class, ['game_id' => $game->id])
            ->assertOk()->assertSee('"joined":true')->assertSee('"user_id":'.$user->id);

        $player = $user->gamePlayers()->sole();
        $this->assertSame($game->id, $player->game_id);
        $this->assertSame(2, $player->seat);
        $this->assertNull($player->bot_difficulty);
        $this->assertTrue($player->is_ready);
        $this->assertSame(GameStatus::Lobby, $game->fresh()->status);
        $sessionState = app(GetGameStateQuery::class)->execute($user, $game->id);
        $this->assertSame($player->id, $sessionState->playerId);
        $this->assertSame($user->id, $sessionState->userId);
        $this->assertSame(0, $game->actions()->count());
        Event::assertDispatched(GamePlayersChanged::class);
        Event::assertDispatched(GameChanged::class, fn (GameChanged $event): bool => $event->gameId === $game->id);
        Event::assertDispatchedTimes(GameChanged::class, 1);
        GameServer::tool(GetGameStateTool::class, ['game_id' => $game->id])->assertOk();
    }

    public function test_join_game_requires_a_game_and_valid_configured_user(): void
    {
        $game = Game::factory()->create();
        config(['game-mcp.user_id' => null]);
        GameServer::tool(JoinGameTool::class, ['game_id' => $game->id])->assertHasErrors();

        config(['game-mcp.user_id' => User::factory()->create()->id]);
        GameServer::tool(JoinGameTool::class, [])->assertHasErrors();
        GameServer::tool(JoinGameTool::class, ['game_id' => $game->id + 1000])->assertHasErrors();
        $this->assertSame(0, $game->players()->count());
    }

    public function test_join_game_marks_existing_lobby_members_ready_without_duplicate_membership(): void
    {
        $user = User::factory()->create();
        $player = GamePlayer::factory()->create(['user_id' => $user->id]);
        config(['game-mcp.user_id' => $user->id]);

        Event::fake([GamePlayersChanged::class]);
        GameServer::tool(JoinGameTool::class, ['game_id' => $player->game_id])
            ->assertOk()->assertSee('"is_ready":true');
        $this->assertTrue($player->fresh()->is_ready);
        Event::assertDispatchedTimes(GamePlayersChanged::class, 1);
        GameServer::tool(JoinGameTool::class, ['game_id' => $player->game_id])->assertOk();
        Event::assertDispatchedTimes(GamePlayersChanged::class, 1);
        $this->assertSame(1, $user->gamePlayers()->count());
    }

    public function test_join_game_rejects_full_lobbies_and_started_games(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create();
        config(['game-mcp.user_id' => $user->id]);
        for ($seat = 1; $seat <= $game->state->board->variant->maxPlayers(); $seat++) {
            GamePlayer::factory()->create(['game_id' => $game->id, 'seat' => $seat]);
        }

        GameServer::tool(JoinGameTool::class, ['game_id' => $game->id])->assertHasErrors();
        foreach ([GameStatus::Active, GameStatus::Finished, GameStatus::Abandoned] as $status) {
            $closedGame = Game::factory()->create(['status' => $status]);
            GameServer::tool(JoinGameTool::class, ['game_id' => $closedGame->id])->assertHasErrors();
            $this->assertSame(0, $closedGame->players()->count());
            $existingPlayer = GamePlayer::factory()->create(['game_id' => $closedGame->id, 'user_id' => $user->id]);
            GameServer::tool(JoinGameTool::class, ['game_id' => $closedGame->id])->assertHasErrors();
            $this->assertFalse($existingPlayer->fresh()->is_ready);
            $this->assertSame(1, $closedGame->players()->count());
        }
        $this->assertSame(3, $user->gamePlayers()->count());
    }

    public function test_user_id_is_not_interpreted_as_a_player_id(): void
    {
        [$game, $player, $actionId] = $this->createGameSession();
        $user = User::factory()->create(['id' => $player->id + 1000]);
        $player->update(['user_id' => $user->id]);
        config(['game-mcp.user_id' => $user->id]);

        GameServer::tool(GetGameStateTool::class, [])->assertOk()->assertSee('"user_id":'.$user->id);
        GameServer::tool(GetLegalGameActionsTool::class, [])->assertOk()->assertSee($actionId);
    }

    public function test_user_without_a_player_cannot_access_a_player_with_the_same_id(): void
    {
        $user = User::factory()->create();
        GamePlayer::factory()->create(['id' => $user->id]);
        config(['game-mcp.user_id' => $user->id]);

        GameServer::tool(GetGameStateTool::class, [])
            ->assertHasErrors()->assertSee('has not joined any game yet');
    }

    public function test_multiple_games_require_an_explicit_game_id(): void
    {
        [$game, $player] = $this->createGameSession();
        GamePlayer::factory()->create(['user_id' => $player->user_id]);

        GameServer::tool(GetGameStateTool::class, [])
            ->assertHasErrors()->assertSee('Specify game_id');
        GameServer::tool(GetGameStateTool::class, ['game_id' => $game->id])
            ->assertOk()->assertSee('"game_id":'.$game->id);
    }

    public function test_explicit_game_id_cannot_access_another_users_game(): void
    {
        $this->createGameSession();
        $otherGame = Game::factory()->create();
        GamePlayer::factory()->create(['game_id' => $otherGame->id]);

        GameServer::tool(GetGameStateTool::class, ['game_id' => $otherGame->id])
            ->assertHasErrors()->assertSee('does not participate');
    }

    public function test_confirmation_executes_once_and_records_only_the_game_action(): void
    {
        [$game, $player, $actionId] = $this->createGameSession();
        Event::fake([GameChanged::class]);
        $arguments = ['version' => $game->version, 'action_id' => $actionId];
        GameServer::tool(ExecuteGameActionTool::class, $arguments)->assertOk();
        $this->assertGreaterThan($game->version, $game->fresh()->version);
        $this->assertNotNull($player->fresh()->homeland);
        $this->assertSame(1, $game->actions()->count());
        Event::assertDispatchedTimes(GameChanged::class, 1);
        GameServer::tool(ExecuteGameActionTool::class, $arguments)->assertHasErrors();
        $this->assertSame(1, $game->actions()->count());
        Event::assertDispatchedTimes(GameChanged::class, 1);
    }

    public function test_stale_versions_invalid_actions_and_missing_versions_are_rejected(): void
    {
        [$game, $player, $actionId] = $this->createGameSession();
        Event::fake([GameChanged::class]);
        GameServer::tool(ExecuteGameActionTool::class, ['version' => $game->version + 1, 'action_id' => $actionId])->assertHasErrors();
        GameServer::tool(ExecuteGameActionTool::class, ['version' => $game->version, 'action_id' => 'invalid'])->assertHasErrors();
        GameServer::tool(ExecuteGameActionTool::class, ['action_id' => $actionId])->assertHasErrors();
        $oldVersion = $game->version;
        $game->increment('version');
        GameServer::tool(ExecuteGameActionTool::class, ['version' => $oldVersion, 'action_id' => $actionId])->assertHasErrors();
        $this->assertNull($player->fresh()->homeland);
        $this->assertSame(0, $game->actions()->count());
        Event::assertNotDispatched(GameChanged::class);
    }

    public function test_inactive_player_cannot_stage_an_action(): void
    {
        [$game, $player, $actionId] = $this->createGameSession();
        config(['game-mcp.user_id' => $game->players()->whereKeyNot($player->id)->sole()->user_id]);
        GameServer::tool(GetLegalGameActionsTool::class, [])->assertOk()->assertDontSee($actionId);
        GameServer::tool(ExecuteGameActionTool::class, ['version' => $game->version, 'action_id' => $actionId])->assertHasErrors();
    }

    public function test_finishing_the_turn_also_requires_staging_and_confirmation(): void
    {
        [$game, $player, $actionId] = $this->createGameSession();
        Event::fake([GameChanged::class]);
        GameServer::tool(ExecuteGameActionTool::class, ['version' => $game->version, 'action_id' => $actionId])->assertOk();
        $game->refresh();
        $state = $game->state;
        $state->round->phase = GamePhase::Actions;
        $state->round->hasTakenMainAction = true;
        $state->round->turnStartVersion = $game->version;
        $game->update(['state' => $state, 'phase' => GamePhase::Actions, 'active_game_player_id' => $player->id]);
        $before = $game->fresh()->getAttributes();
        GameServer::tool(GetLegalGameActionsTool::class, [])->assertOk()->assertSee('finish_turn');
        $this->stageSelection($game, $player, 'finish_turn');
        app(RollbackGameAction::class)->execute($player->user, $game->id, $game->version);
        $this->assertSame($before, $game->fresh()->getAttributes());
        GameServer::tool(ExecuteGameActionTool::class, ['version' => $game->version, 'action_id' => 'finish_turn'])->assertOk();
        $this->assertNotSame($player->id, $game->fresh()->active_game_player_id);
        $this->assertFalse($game->fresh()->state->round->hasTakenMainAction);
        Event::assertDispatchedTimes(GameChanged::class, 2);
    }

    private function stageSelection(Game $game, GamePlayer $player, string $id): void
    {
        $options = app(GetLegalGameActionsQuery::class)->execute($player->user, $game->id);
        app(StageGameAction::class)->execute($player->user, $game->id, $game->version, app(GameActionIdentifier::class)->resolve($id, $options));
    }

    /** @return array{Game, GamePlayer, string} */
    private function createGameSession(): array
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create(['random_seed' => 'mcp-game']);
        foreach ($users as $index => $user) {
            GamePlayer::factory()->ready()->create(['game_id' => $game->id, 'user_id' => $user->id, 'seat' => $index + 1]);
        }
        $this->actingAs($users[0])->post(route('games.start', $game))->assertNoContent();
        $this->app['auth']->forgetGuards();
        $game->refresh();
        $game->actions()->delete();
        $player = $game->players()->findOrFail($game->active_game_player_id);
        config(['game-mcp.user_id' => $player->user_id]);
        Cache::forget('game:staged-action:'.$player->id);
        $option = app(GameActionOptionFinder::class)->execute($game->state, $player->id)[0];

        return [$game, $player, app(GameActionIdentifier::class)->forOption($option, $game->version)];
    }
}
