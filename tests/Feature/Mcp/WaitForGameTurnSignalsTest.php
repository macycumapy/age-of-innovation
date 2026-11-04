<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameSession\Enums\GameTurnWaitReason;
use App\Domain\GameSession\Queries\WaitForGameTurnQuery;
use App\Domain\GameSession\Services\GameChangeSignal;
use App\Domain\GameSession\Services\GameWaitObserver;
use App\Models\Game;
use App\Models\GamePlayer;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WaitForGameTurnSignalsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Sleep::fake(false);
        parent::tearDown();
    }

    public function test_an_idle_wait_only_reads_the_database_before_waiting_and_at_timeout(): void
    {
        [$game, $player] = $this->createGameSession();
        $user = $player->user;
        $level = DB::transactionLevel();
        $queries = [];
        Sleep::fake();
        DB::listen(function (QueryExecuted $query) use (&$queries, $level): void {
            $this->assertSame($level, DB::transactionLevel());
            $queries[] = $query->sql;
        });
        $signals = Mockery::mock(GameChangeSignal::class);
        $signals->shouldReceive('cursor')->once()->with($game->id)->andReturn('0-0');
        $signals->shouldReceive('wait')->once()
            ->with($game->id, '0-0', Mockery::on(fn (int $timeout): bool => $timeout > 29000 && $timeout <= 30000))
            ->andReturn('0-0');
        $this->app->instance(GameChangeSignal::class, $signals);

        $result = app(WaitForGameTurnQuery::class)->execute($user, $game->id);

        $this->assertSame(GameTurnWaitReason::Timeout, $result->reason);
        $this->assertCount(3, $queries);
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('for update', strtolower($sql));
            $this->assertStringNotContainsString('*', $sql);
        }
        Sleep::assertNeverSlept();
    }

    public function test_a_change_while_capturing_the_cursor_is_observed_before_blocking(): void
    {
        [$game, $player] = $this->createGameSession();
        $signals = Mockery::mock(GameChangeSignal::class);
        $signals->shouldReceive('cursor')->once()->with($game->id)->andReturnUsing(function () use ($game, $player): string {
            $game->update(['active_game_player_id' => $player->id, 'version' => 4]);

            return '1-0';
        });
        $signals->shouldNotReceive('wait');
        $this->app->instance(GameChangeSignal::class, $signals);

        $result = app(WaitForGameTurnQuery::class)->execute($player->user, null, 2);

        $this->assertSame(GameTurnWaitReason::YourTurn, $result->reason);
        $this->assertSame(4, $result->view->version);
        $this->assertSame(4, $result->actions->version);
    }

    public function test_a_notification_between_the_last_check_and_wait_uses_the_captured_cursor(): void
    {
        [$game, $player] = $this->createGameSession();
        $user = $player->user;
        $checks = 0;
        DB::listen(function (QueryExecuted $query) use ($game, $player, &$checks): void {
            if (str_contains($query->sql, 'pending_player_id') && ++$checks === 2) {
                $game->update(['active_game_player_id' => $player->id, 'version' => 5]);
            }
        });
        $signals = Mockery::mock(GameChangeSignal::class);
        $signals->shouldReceive('cursor')->once()->with($game->id)->andReturn('1-0');
        $signals->shouldReceive('wait')->once()->with($game->id, '1-0', Mockery::type('int'))->andReturn('2-0');
        $this->app->instance(GameChangeSignal::class, $signals);

        $result = app(WaitForGameTurnQuery::class)->execute($user, $game->id, 2);

        $this->assertSame(GameTurnWaitReason::YourTurn, $result->reason);
        $this->assertSame(5, $result->view->version);
        $this->assertSame(5, $result->actions->version);
    }

    public function test_notifications_for_other_players_continue_waiting_from_the_new_cursor(): void
    {
        [$game, $player] = $this->createGameSession();
        $signals = Mockery::mock(GameChangeSignal::class);
        $signals->shouldReceive('cursor')->once()->with($game->id)->andReturn('0-0');
        $signals->shouldReceive('wait')->once()->with($game->id, '0-0', Mockery::type('int'))->andReturn('1-0');
        $signals->shouldReceive('wait')->once()->with($game->id, '1-0', Mockery::type('int'))->andReturnUsing(function () use ($game, $player): string {
            $state = $game->state;
            $state->pendingInteraction = new PendingInteractionData(PendingInteractionType::PowerOffer, $player->id);
            $game->update(['state' => $state]);

            return '2-0';
        });
        $this->app->instance(GameChangeSignal::class, $signals);

        $result = app(WaitForGameTurnQuery::class)->execute($player->user, $game->id, 2);

        $this->assertSame(GameTurnWaitReason::YourTurn, $result->reason);
        $this->assertNull($result->view->activePlayerId);
    }

    public function test_a_missing_notification_is_caught_by_the_final_database_check(): void
    {
        [$game, $player] = $this->createGameSession();
        $signals = Mockery::mock(GameChangeSignal::class);
        $signals->shouldReceive('cursor')->once()->andReturn('0-0');
        $signals->shouldReceive('wait')->once()->andReturnUsing(function () use ($game, $player): string {
            $game->update(['active_game_player_id' => $player->id]);

            return '0-0';
        });
        $this->app->instance(GameChangeSignal::class, $signals);

        $result = app(WaitForGameTurnQuery::class)->execute($player->user, $game->id, 2);

        $this->assertSame(GameTurnWaitReason::YourTurn, $result->reason);
    }

    public function test_membership_is_rechecked_after_a_notification(): void
    {
        [$game, $player] = $this->createGameSession();
        $user = $player->user;
        $signals = Mockery::mock(GameChangeSignal::class);
        $signals->shouldReceive('cursor')->once()->andReturn('0-0');
        $signals->shouldReceive('wait')->once()->andReturnUsing(function () use ($player): string {
            $player->delete();

            return '1-0';
        });
        $this->app->instance(GameChangeSignal::class, $signals);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The user does not participate in this game');

        app(WaitForGameTurnQuery::class)->execute($user, $game->id, 2);
    }

    #[DataProvider('unavailableTransports')]
    public function test_redis_failure_falls_back_to_polling(bool $failsDuringWait): void
    {
        [$game, $player] = $this->createGameSession();
        Sleep::fake();
        Sleep::whenFakingSleep(fn () => $game->update(['active_game_player_id' => $player->id]));
        $signals = Mockery::mock(GameChangeSignal::class);
        $signals->shouldReceive('cursor')->once()->andReturn($failsDuringWait ? '0-0' : null);
        if ($failsDuringWait) {
            $signals->shouldReceive('wait')->once()->andReturnNull();
        } else {
            $signals->shouldNotReceive('wait');
        }
        $this->app->instance(GameChangeSignal::class, $signals);
        $observer = Mockery::mock(GameWaitObserver::class);
        $observer->shouldReceive('waiting')->once()->with($game->id, $failsDuringWait);
        if ($failsDuringWait) {
            $observer->shouldReceive('notificationsUnavailable')->once()->with($game->id);
        } else {
            $observer->shouldNotReceive('notificationsUnavailable');
        }
        $this->app->instance(GameWaitObserver::class, $observer);

        $result = app(WaitForGameTurnQuery::class)->execute($player->user, $game->id, 2);

        $this->assertSame(GameTurnWaitReason::YourTurn, $result->reason);
        Sleep::assertSleptTimes(1);
    }

    /** @return list<array{bool}> */
    public static function unavailableTransports(): array
    {
        return [[false], [true]];
    }

    public function test_game_end_wakes_the_waiter_without_actions(): void
    {
        [$game, $player] = $this->createGameSession();
        $signals = Mockery::mock(GameChangeSignal::class);
        $signals->shouldReceive('cursor')->once()->andReturn('0-0');
        $signals->shouldReceive('wait')->once()->andReturnUsing(function () use ($game): string {
            $game->update(['status' => GameStatus::Finished]);

            return '1-0';
        });
        $this->app->instance(GameChangeSignal::class, $signals);

        $result = app(WaitForGameTurnQuery::class)->execute($player->user, $game->id, 2);

        $this->assertSame(GameTurnWaitReason::GameEnded, $result->reason);
        $this->assertNotNull($result->view);
        $this->assertNull($result->actions);
    }

    public function test_zero_timeout_does_not_access_redis(): void
    {
        [$game, $player] = $this->createGameSession();
        $signals = Mockery::mock(GameChangeSignal::class);
        $signals->shouldNotReceive('cursor');
        $signals->shouldNotReceive('wait');
        $this->app->instance(GameChangeSignal::class, $signals);
        $observer = Mockery::mock(GameWaitObserver::class);
        $observer->shouldNotReceive('waiting');
        $observer->shouldNotReceive('notificationsUnavailable');
        $this->app->instance(GameWaitObserver::class, $observer);

        $this->assertSame(GameTurnWaitReason::Timeout, app(WaitForGameTurnQuery::class)->execute($player->user, $game->id, 0)->reason);
    }

    /** @return array{Game, GamePlayer} */
    private function createGameSession(): array
    {
        $game = Game::factory()->active()->create();
        $player = GamePlayer::factory()->for($game)->create();

        return [$game, $player];
    }
}
