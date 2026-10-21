<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameSession\Enums\GameTurnWaitReason;
use App\Domain\GameSession\Queries\GetGameTurnContextQuery;
use App\Domain\GameSession\Queries\WaitForGameTurnQuery;
use App\Mcp\Presentation\GameStatePresenter;
use App\Mcp\Server\GameServer;
use App\Mcp\Tools\WaitForGameTurnTool;
use App\Mcp\Validators\WaitForGameTurnValidator;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Laravel\Mcp\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WaitForGameTurnTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Sleep::fake(false);
        parent::tearDown();
    }

    public function test_own_turn_returns_immediately_without_changing_game_or_history(): void
    {
        [$game, $player] = $this->createGameSession();
        $game->update(['active_game_player_id' => $player->id]);
        $before = $game->fresh()->getAttributes();
        Sleep::fake();

        GameServer::tool(WaitForGameTurnTool::class, ['game_id' => $game->id])
            ->assertOk()->assertSee('your_turn');

        Sleep::assertNeverSlept();
        $this->assertSame($before, $game->fresh()->getAttributes());
        $this->assertSame(0, $game->actions()->count());
        $annotations = (array) app(WaitForGameTurnTool::class)->toArray()['annotations'];
        $this->assertTrue($annotations['readOnlyHint']);
    }

    public function test_wait_releases_transaction_and_observes_a_new_turn(): void
    {
        [$game, $player] = $this->createGameSession();
        $level = DB::transactionLevel();
        Sleep::fake();
        Sleep::whenFakingSleep(function () use ($game, $player, $level): void {
            $this->assertSame($level, DB::transactionLevel());
            $game->update(['active_game_player_id' => $player->id, 'version' => 4]);
        });

        $result = app(WaitForGameTurnQuery::class)->execute($player->user, null, 2);

        $this->assertSame(GameTurnWaitReason::YourTurn, $result->reason);
        $this->assertSame(4, $result->view->version);
        $this->assertSame(4, $result->actions->version);
        Sleep::assertSleptTimes(1);
    }

    public function test_timeout_is_bounded_and_zero_timeout_does_not_sleep(): void
    {
        [$game, $player] = $this->createGameSession();
        Sleep::fake();
        $query = app(WaitForGameTurnQuery::class);
        $this->assertSame(GameTurnWaitReason::Timeout, $query->execute($player->user, $game->id, 0)->reason);
        Sleep::assertNeverSlept();
        $this->assertSame(GameTurnWaitReason::Timeout, $query->execute($player->user, $game->id, 2)->reason);
        Sleep::assertSleptTimes(2);
    }

    public function test_timeout_does_not_build_state_or_read_staged_actions_on_any_poll(): void
    {
        [$game, $player] = $this->createGameSession();
        $user = $player->user;
        Sleep::fake();
        Cache::spy();

        $result = app(WaitForGameTurnQuery::class)->execute($user, $game->id, 2);

        $this->assertSame(GameTurnWaitReason::Timeout, $result->reason);
        $this->assertSame($game->id, $result->gameId);
        $this->assertNull($result->view);
        $this->assertNull($result->actions);
        Cache::shouldNotHaveReceived('get');
        Sleep::assertSleptTimes(2);
    }

    public function test_pending_choice_takes_precedence_over_active_player(): void
    {
        [$game, $player] = $this->createGameSession();
        $state = $game->state;
        $state->pendingInteraction = new PendingInteractionData(PendingInteractionType::PowerOffer, $player->id);
        $game->update(['state' => $state]);
        $query = app(WaitForGameTurnQuery::class);
        $this->assertSame(GameTurnWaitReason::YourTurn, $query->execute($player->user, $game->id, 0)->reason);

        $state->pendingInteraction->playerId = $player->id + 1;
        $game->update(['state' => $state, 'active_game_player_id' => $player->id]);
        $this->assertSame(GameTurnWaitReason::Timeout, $query->execute($player->user, $game->id, 0)->reason);
    }

    public function test_lobby_waits_and_finished_or_abandoned_games_stop_waiting(): void
    {
        [$game, $player] = $this->createGameSession();
        $query = app(WaitForGameTurnQuery::class);
        $game->update(['status' => GameStatus::Lobby, 'active_game_player_id' => $player->id]);
        $this->assertSame(GameTurnWaitReason::Timeout, $query->execute($player->user, $game->id, 0)->reason);
        foreach ([GameStatus::Finished, GameStatus::Abandoned] as $status) {
            $game->update(['status' => $status]);
            $this->assertSame(GameTurnWaitReason::GameEnded, $query->execute($player->user, $game->id, 20)->reason);
        }
    }

    public function test_non_member_is_rejected_without_waiting(): void
    {
        [$game] = $this->createGameSession();
        config(['game-mcp.user_id' => User::factory()->create()->id]);
        Sleep::fake();
        GameServer::tool(WaitForGameTurnTool::class, ['game_id' => $game->id])->assertHasErrors();
        Sleep::assertNeverSlept();
    }

    public function test_membership_is_rechecked_after_waiting(): void
    {
        [$game, $player] = $this->createGameSession();
        Sleep::fake();
        Sleep::whenFakingSleep(fn () => $player->delete());

        GameServer::tool(WaitForGameTurnTool::class, ['game_id' => $game->id, 'timeout_seconds' => 2])
            ->assertHasErrors();

        Sleep::assertSleptTimes(1);
    }

    public function test_ambiguous_game_requires_an_explicit_id(): void
    {
        [$game, $player] = $this->createGameSession();
        GamePlayer::factory()->create(['user_id' => $player->user_id]);
        Sleep::fake();

        GameServer::tool(WaitForGameTurnTool::class, ['timeout_seconds' => 0])->assertHasErrors();
        GameServer::tool(WaitForGameTurnTool::class, ['game_id' => $game->id, 'timeout_seconds' => 0])
            ->assertOk()->assertSee('timeout');
        Sleep::assertNeverSlept();
    }

    public function test_game_ending_during_wait_returns_without_exhausting_timeout(): void
    {
        [$game, $player] = $this->createGameSession();
        Sleep::fake();
        Sleep::whenFakingSleep(fn () => $game->update(['status' => GameStatus::Finished]));

        $result = app(WaitForGameTurnQuery::class)->execute($player->user, $game->id, 20);

        $this->assertSame(GameTurnWaitReason::GameEnded, $result->reason);
        Sleep::assertSleptTimes(1);
    }

    public function test_validator_defaults_and_tool_schema_agree(): void
    {
        $validator = new WaitForGameTurnValidator();
        $this->assertNull($validator->validate(new Request())->gameId);
        $this->assertSame(30, $validator->validate(new Request())->timeoutSeconds);
        $data = $validator->validate(new Request(['game_id' => '12', 'timeout_seconds' => '0']));
        $this->assertSame(12, $data->gameId);
        $this->assertSame(0, $data->timeoutSeconds);
        $schema = app(WaitForGameTurnTool::class)->toArray()['inputSchema']['properties']['timeout_seconds'];
        $this->assertSame(0, $schema['minimum']);
        $this->assertSame(30, $schema['maximum']);
    }

    public function test_http_uses_token_owner_and_returns_structured_state(): void
    {
        [$game, $player] = $this->createGameSession();
        $game->update(['active_game_player_id' => $player->id]);
        config(['game-mcp.user_id' => User::factory()->create()->id]);
        $token = $player->user->createToken('test', ['game:mcp'])->plainTextToken;
        $expected = app(GameStatePresenter::class)->turnContext(app(GetGameTurnContextQuery::class)->execute($player->user, $game->id));
        $response = $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'wait_for_turn', 'arguments' => ['game_id' => $game->id, 'timeout_seconds' => 0]],
        ])->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.reason', 'your_turn')
            ->assertJsonStructure(['result' => ['structuredContent' => ['state', 'version', 'actions']]])
            ->assertJsonPath('result.structuredContent.player_id', $player->id);

        $this->assertSame(['reason' => 'your_turn', ...$expected], $response->json('result.structuredContent'));
    }

    public function test_timeout_returns_only_reason_in_both_structured_and_text_content(): void
    {
        [$game, $player] = $this->createGameSession();
        Sleep::fake();
        GameServer::tool(WaitForGameTurnTool::class, ['game_id' => $game->id, 'timeout_seconds' => 0])
            ->assertOk()->assertSee('timeout')->assertDontSee('state')->assertDontSee('version');

        $token = $player->user->createToken('test', ['game:mcp'])->plainTextToken;
        $response = $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'wait_for_turn', 'arguments' => ['game_id' => $game->id, 'timeout_seconds' => 2]],
        ])->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent', ['reason' => 'timeout']);

        $this->assertSame(['reason' => 'timeout'], json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR));
        Sleep::assertSleptTimes(2);
    }

    public function test_game_ended_still_returns_the_visible_game_state(): void
    {
        [$game, $player] = $this->createGameSession();
        $game->update(['status' => GameStatus::Finished]);
        $token = $player->user->createToken('test', ['game:mcp'])->plainTextToken;

        $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'wait_for_turn', 'arguments' => ['game_id' => $game->id, 'timeout_seconds' => 0]],
        ])->assertOk()->assertJsonPath('result.structuredContent.reason', 'game_ended')
            ->assertJsonPath('result.structuredContent.game_id', $game->id)
            ->assertJsonMissingPath('result.structuredContent.actions')
            ->assertJsonStructure(['result' => ['structuredContent' => ['state', 'version']]]);
    }

    #[DataProvider('invalidArguments')]
    /** @param array<string, mixed> $arguments */
    public function test_invalid_parameters_are_rejected(array $arguments): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        (new WaitForGameTurnValidator())->validate(new Request($arguments));
    }

    /** @return list<array{array<string, mixed>}> */
    public static function invalidArguments(): array
    {
        return [[['timeout_seconds' => -1]], [['timeout_seconds' => 31]], [['timeout_seconds' => 0.5]], [['timeout_seconds' => null]], [['game_id' => 0]]];
    }

    public function test_default_wait_stays_at_thirty_seconds_after_timeouts_and_success(): void
    {
        [$game, $player] = $this->createGameSession();
        Sleep::fake();
        Cache::spy();
        $query = app(WaitForGameTurnQuery::class);

        $this->assertSame(GameTurnWaitReason::Timeout, $query->execute($player->user, $game->id)->reason);
        Sleep::assertSleptTimes(30);
        $this->assertSame(GameTurnWaitReason::Timeout, $query->execute($player->user, $game->id)->reason);
        Sleep::assertSleptTimes(60);
        Cache::shouldNotHaveReceived('put');
        Cache::shouldNotHaveReceived('forget');

        $game->update(['active_game_player_id' => $player->id]);
        $this->assertSame(GameTurnWaitReason::YourTurn, $query->execute($player->user, $game->id)->reason);
        Sleep::assertSleptTimes(60);
        $game->update(['active_game_player_id' => null]);
        $this->assertSame(GameTurnWaitReason::Timeout, $query->execute($player->user, $game->id)->reason);
        Sleep::assertSleptTimes(90);
    }

    public function test_default_wait_is_the_same_for_other_users_and_games(): void
    {
        [, $firstPlayer] = $this->createGameSession();
        [$game, $secondPlayer] = $this->createGameSession();
        Sleep::fake();
        $query = app(WaitForGameTurnQuery::class);

        $this->assertSame(GameTurnWaitReason::Timeout, $query->execute($firstPlayer->user)->reason);
        Sleep::assertSleptTimes(30);
        $this->assertSame(GameTurnWaitReason::Timeout, $query->execute($secondPlayer->user, $game->id)->reason);
        Sleep::assertSleptTimes(60);
    }

    public function test_explicit_short_wait_does_not_change_next_default_wait(): void
    {
        [$game, $player] = $this->createGameSession();
        Sleep::fake();
        $query = app(WaitForGameTurnQuery::class);

        $this->assertSame(GameTurnWaitReason::Timeout, $query->execute($player->user, $game->id, 2)->reason);
        Sleep::assertSleptTimes(2);
        $this->assertSame(GameTurnWaitReason::Timeout, $query->execute($player->user, $game->id)->reason);
        Sleep::assertSleptTimes(32);
        $game->update(['status' => GameStatus::Finished]);
        $this->assertSame(GameTurnWaitReason::GameEnded, $query->execute($player->user, $game->id)->reason);
        Sleep::assertSleptTimes(32);
    }

    public function test_default_http_wait_is_thirty_seconds_without_adding_state_to_timeout_response(): void
    {
        [$game, $player] = $this->createGameSession();
        Sleep::fake();
        $token = $player->user->createToken('test', ['game:mcp'])->plainTextToken;
        $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'wait_for_turn', 'arguments' => ['game_id' => $game->id]],
        ])->assertOk()->assertJsonPath('result.structuredContent', ['reason' => 'timeout']);
        Sleep::assertSleptTimes(30);
    }

    /** @return array{Game, GamePlayer} */
    private function createGameSession(): array
    {
        $game = Game::factory()->create(['status' => GameStatus::Active]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id]);
        config(['game-mcp.user_id' => $player->user_id]);

        return [$game, $player];
    }
}
