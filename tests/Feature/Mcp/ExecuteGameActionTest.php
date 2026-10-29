<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Setup\Actions\StartGameAction;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Domain\GameSession\Queries\GetGameTurnContextQuery;
use App\Domain\GameSession\Services\StagedGameActionStore;
use App\Events\GameChanged;
use App\Mcp\Presentation\GameStatePresenter;
use App\Mcp\Server\GameServer;
use App\Mcp\Stores\GameContextSnapshotStore;
use App\Mcp\Tools\ExecuteGameActionTool;
use App\Mcp\Tools\RollbackGameActionTool;
use App\Mcp\Tools\StageGameActionTool;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExecuteGameActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_http_call_executes_once_and_returns_the_confirmation_protocol(): void
    {
        [$game, $player, $context] = $this->createSession();
        config(['cache.stores.array.serialize' => true, 'cache.serializable_classes' => false]);
        Cache::purge('array');
        $token = $player->user->createToken('test', ['game:mcp'])->plainTextToken;
        config(['game-mcp.user_id' => User::factory()->create()->id]);
        $arguments = ['game_id' => $game->id, 'version' => $game->version, 'action_id' => $context['actions'][0]['action_id']];
        $count = $game->actions()->count();
        Event::fake([GameChanged::class]);
        $body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'execute_action', 'arguments' => $arguments]];
        $response = $this->withToken($token)->postJson('/mcp/game', $body)
            ->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.confirmed', true)
            ->assertJsonPath('result.structuredContent.version', $game->version + 1)
            ->assertJsonPath('result.structuredContent.action', ['type' => $context['actions'][0]['type'], 'parameters' => $context['actions'][0]['parameters']]);
        $this->assertSame($count + 1, $game->actions()->count());
        $this->assertNull(app(StagedGameActionStore::class)->get($player));
        $this->assertSame($response->json('result.structuredContent'), json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR));
        Event::assertDispatchedTimes(GameChanged::class, 1);

        $this->withToken($token)->postJson('/mcp/game', $body)->assertOk()->assertJsonPath('result.isError', true);
        $this->assertSame($count + 1, $game->actions()->count());
        Event::assertDispatchedTimes(GameChanged::class, 1);
    }

    public function test_own_turn_returns_fresh_actions_and_a_cached_context_update(): void
    {
        [$game, $player, $context] = $this->createSession(true);
        $action = collect($context['actions'])->firstWhere('type', 'exchange_resources');
        $token = $player->user->createToken('test', ['game:mcp'])->plainTextToken;
        $response = $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'execute_action', 'arguments' => ['game_id' => $game->id, 'version' => $game->version, 'action_id' => $action['action_id']]],
        ])->assertOk()->assertJsonPath('result.isError', false)->assertJsonPath('result.structuredContent.continuation', 'your_turn');
        $update = $response->json('result.structuredContent.context_update');
        $cached = app(GameContextSnapshotStore::class)->find($player->user_id, $game->id, $player->id, $update['context_id']);
        $fresh = app(GameStatePresenter::class)->turnContext(app(GetGameTurnContextQuery::class)->execute($player->user, $game->id));
        $this->assertSame($fresh, $cached);
        $this->assertNotEmpty($update['actions'] ?? $update['context']['actions']);
        $this->assertSame($game->version + 1, $fresh['version']);
    }

    public function test_existing_manual_selection_is_preserved_until_explicit_rollback(): void
    {
        [$game, $player, $context] = $this->createSession();
        $arguments = ['game_id' => $game->id, 'version' => $game->version, 'action_id' => $context['actions'][0]['action_id']];
        $before = $game->fresh()->getAttributes();
        GameServer::tool(StageGameActionTool::class, $arguments)->assertOk();
        $selection = app(StagedGameActionStore::class)->get($player);
        GameServer::tool(ExecuteGameActionTool::class, $arguments)->assertHasErrors()->assertSee('A staged action already exists');
        $this->assertEquals($selection, app(StagedGameActionStore::class)->get($player));
        $this->assertSame($before, $game->fresh()->getAttributes());
        GameServer::tool(RollbackGameActionTool::class, $arguments)->assertOk();
        GameServer::tool(ExecuteGameActionTool::class, $arguments)->assertOk();
    }

    public function test_failure_after_staging_rolls_back_game_and_removes_temporary_selection(): void
    {
        [$game, $player, $context] = $this->createSession();
        $before = $game->fresh()->getAttributes();
        $count = $game->actions()->count();
        Event::fake([GameChanged::class]);
        Event::listen('eloquent.updating: '.Game::class, function (): void {
            throw new DomainException('Simulated execution failure');
        });
        GameServer::tool(ExecuteGameActionTool::class, ['game_id' => $game->id, 'version' => $game->version, 'action_id' => $context['actions'][0]['action_id']])
            ->assertHasErrors()->assertSee('Simulated execution failure');
        $this->assertSame($before, $game->fresh()->getAttributes());
        $this->assertSame($count, $game->actions()->count());
        $this->assertNull(app(StagedGameActionStore::class)->get($player));
        Event::assertNotDispatched(GameChanged::class);
    }

    #[DataProvider('invalidCalls')]
    /** @param array<string, mixed> $overrides */
    public function test_invalid_arguments_and_stale_versions_do_not_mutate_the_game(array $overrides): void
    {
        [$game, $player, $context] = $this->createSession();
        $before = $game->fresh()->getAttributes();
        $count = $game->actions()->count();
        GameServer::tool(ExecuteGameActionTool::class, array_replace(['game_id' => $game->id, 'version' => $game->version, 'action_id' => $context['actions'][0]['action_id']], $overrides))->assertHasErrors();
        $this->assertSame($before, $game->fresh()->getAttributes());
        $this->assertSame($count, $game->actions()->count());
        $this->assertNull(app(StagedGameActionStore::class)->get($player));
    }

    /** @return list<array{array<string, mixed>}> */
    public static function invalidCalls(): array
    {
        return [[['version' => -1]], [['version' => 999]], [['version' => null]], [['action_id' => 'invalid']], [['action_id' => 'finish_turn']], [['action_id' => null]], [['game_id' => 0]]];
    }

    public function test_finishing_turn_uses_the_same_single_call_workflow(): void
    {
        [$game, , $context] = $this->createSession(true);
        $state = $game->state;
        $state->round->hasTakenMainAction = true;
        $state->round->turnStartVersion = $game->version;
        $game->update(['state' => $state]);
        $count = $game->actions()->count();
        GameServer::tool(ExecuteGameActionTool::class, ['game_id' => $game->id, 'version' => $game->version, 'action_id' => 'finish_turn'])
            ->assertOk()->assertSee('"type":"finish_turn"')->assertSee('"continuation":"wait_for_turn"');
        $this->assertSame($count + 1, $game->actions()->count());
    }

    public function test_http_requires_authentication_ability_and_membership(): void
    {
        [$game, , $context] = $this->createSession();
        $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'execute_action', 'arguments' => ['game_id' => $game->id, 'version' => $game->version, 'action_id' => $context['actions'][0]['action_id']]]];
        $this->postJson('/mcp/game', $request)->assertUnauthorized();
        $user = User::factory()->create();
        $this->withToken($user->createToken('test', ['profile:read'])->plainTextToken)->postJson('/mcp/game', $request)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('test', ['game:mcp'])->plainTextToken)->postJson('/mcp/game', $request)->assertOk()->assertJsonPath('result.isError', true);
    }

    /** @return array{Game, GamePlayer, array<string, mixed>} */
    private function createSession(bool $actionPhase = false): array
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create(['random_seed' => 'execute-action']);
        foreach ($users as $index => $user) {
            GamePlayer::factory()->ready()->create(['game_id' => $game->id, 'user_id' => $user->id, 'seat' => $index + 1]);
        }
        $game = app(StartGameAction::class)->execute($game, $users[0]);
        $player = $game->activeGamePlayer;
        config(['game-mcp.user_id' => $player->user_id]);
        if ($actionPhase) {
            $state = $game->state;
            $state->round->phase = GamePhase::Actions;
            $state->players = $game->players->map(fn (GamePlayer $p): GamePlayerStateData => new GamePlayerStateData(
                playerId: $p->id,
                userId: $p->user_id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(coins: 5, tools: 3),
            ))->all();
            $game->update(['state' => $state, 'phase' => GamePhase::Actions]);
        }
        $context = app(GameStatePresenter::class)->turnContext(app(GetGameTurnContextQuery::class)->execute($player->user, $game->id));

        return [$game, $player, $context];
    }
}
