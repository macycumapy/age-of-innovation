<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\GameSession\Services\GameChangeSignal;
use App\Domain\GameSession\Services\GameWaitMetrics;
use App\Domain\GameSession\Services\RedisGameChangeSignal;
use App\Events\GameChanged;
use App\Events\GamePlayersChanged;
use App\Models\Game;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class RedisGameChangeSignalTest extends TestCase
{
    use RefreshDatabase;

    private RedisManager $redis;

    private RedisGameChangeSignal $signals;

    /** @var array<string, mixed> */
    private array $redisConfiguration;

    protected function setUp(): void
    {
        parent::setUp();

        config(['game-mcp.wait.enabled' => true]);
        $this->redisConfiguration = config('database.redis');
        $this->redisConfiguration['options']['prefix'] = 'test:game-turn-signals:'.bin2hex(random_bytes(8)).':';
        $this->redis = new RedisManager($this->app, $this->redisConfiguration['client'], $this->redisConfiguration);
        $this->signals = new RedisGameChangeSignal($this->redis, app(GameWaitMetrics::class));
        $this->app->instance(GameChangeSignal::class, $this->signals);
    }

    protected function tearDown(): void
    {
        $this->redis->connection('game_signals')->del('game:turn-signals:1', 'game:turn-signals:2');
        $this->redis->purge('game_signals');
        $this->redis->purge('game_wait');

        parent::tearDown();
    }

    public function test_an_event_retained_before_waiting_can_be_read_by_multiple_waiters(): void
    {
        $cursor = $this->signals->cursor(1);
        $this->assertSame('0-0', $cursor);
        $this->signals->publish(1);
        $next = $this->signals->cursor(1);

        $this->assertNotSame($cursor, $next);
        $this->assertSame($next, $this->signals->wait(1, $cursor, 100));
        $this->assertSame($next, $this->signals->wait(1, $cursor, 100));
    }

    public function test_blocked_waiters_both_receive_the_same_published_event(): void
    {
        $script = <<<'PHP'
            $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
            require $input['autoload'];
            $container = new Illuminate\Container\Container;
            $container->instance('config', new Illuminate\Config\Repository(['game-mcp' => ['wait' => ['enabled' => true]]]));
            Illuminate\Container\Container::setInstance($container);
            $redis = new Illuminate\Redis\RedisManager($container, $input['redis']['client'], $input['redis']);
            $metrics = new App\Domain\GameSession\Services\GameWaitMetrics(new Illuminate\Log\LogManager($container));
            $signals = new App\Domain\GameSession\Services\RedisGameChangeSignal($redis, $metrics);
            echo "ready\n";
            flush();
            echo json_encode($signals->wait(1, '0-0', 2000), JSON_THROW_ON_ERROR)."\n";
            PHP;
        $input = json_encode(['autoload' => base_path('vendor/autoload.php'), 'redis' => $this->redisConfiguration], JSON_THROW_ON_ERROR);
        $processes = [new Process([PHP_BINARY, '-r', $script], input: $input, timeout: 5), new Process([PHP_BINARY, '-r', $script], input: $input, timeout: 5)];

        try {
            foreach ($processes as $process) {
                $process->start();
                $this->assertTrue($process->waitUntil(fn (): bool => str_contains($process->getOutput(), "ready\n")));
            }
            $this->signals->publish(1);
            $next = $this->signals->cursor(1);
            $this->assertNotSame('0-0', $next);

            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $lines = explode("\n", trim($process->getOutput()));
                $this->assertSame($next, json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR));
            }
        } finally {
            foreach ($processes as $process) {
                $process->stop(0.1);
            }
        }
    }

    public function test_wait_timeout_returns_the_same_cursor_without_affecting_other_games(): void
    {
        $this->signals->publish(2);

        $this->assertSame('0-0', $this->signals->wait(1, '0-0', 1100));
        $this->assertSame('0-0', $this->signals->cursor(1));
        $this->assertNotSame('0-0', $this->signals->cursor(2));
    }

    public function test_zero_wait_never_blocks(): void
    {
        $factory = Mockery::mock(Factory::class);
        $factory->shouldNotReceive('connection');

        $this->assertSame('0-0', (new RedisGameChangeSignal($factory, app(GameWaitMetrics::class)))->wait(1, '0-0', 0));
    }

    public function test_streams_are_bounded_and_expire(): void
    {
        for ($i = 0; $i < RedisGameChangeSignal::MAX_ENTRIES + 5; $i++) {
            $this->signals->publish(1);
        }

        $connection = $this->redis->connection('game_signals');
        $this->assertSame(RedisGameChangeSignal::MAX_ENTRIES, $connection->xlen('game:turn-signals:1'));
        $this->assertGreaterThan(0, $connection->ttl('game:turn-signals:1'));
        $this->assertLessThanOrEqual(RedisGameChangeSignal::TTL_SECONDS, $connection->ttl('game:turn-signals:1'));
    }

    #[DataProvider('changeEvents')]
    public function test_signals_are_only_published_after_the_transaction_commits(string $eventClass): void
    {
        DB::transaction(function () use ($eventClass): void {
            $eventClass::dispatch(1);
            $this->assertSame('0-0', $this->signals->cursor(1));
        });

        $this->assertNotSame('0-0', $this->signals->cursor(1));
    }

    #[DataProvider('changeEvents')]
    public function test_rolling_back_a_transaction_does_not_publish_a_signal(string $eventClass): void
    {
        DB::beginTransaction();
        $eventClass::dispatch(1);
        DB::rollBack();

        $this->assertSame('0-0', $this->signals->cursor(1));
    }

    /** @return list<array{class-string<GameChanged>|class-string<GamePlayersChanged>}> */
    public static function changeEvents(): array
    {
        return [[GameChanged::class], [GamePlayersChanged::class]];
    }

    public function test_a_publication_failure_does_not_fail_a_committed_game_change(): void
    {
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->with('game_signals')->andThrow(new RuntimeException('Redis unavailable'));
        $this->app->instance(GameChangeSignal::class, new RedisGameChangeSignal($factory, app(GameWaitMetrics::class)));

        $game = DB::transaction(function (): Game {
            $game = Game::factory()->create();
            GameChanged::dispatch($game->id);

            return $game;
        });

        $this->assertModelExists($game);
    }

    public function test_redis_connection_errors_make_notifications_unavailable(): void
    {
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->with('game_signals')->once()->andThrow(new RuntimeException('Redis unavailable'));
        $factory->shouldReceive('connection')->with('game_wait')->once()->andThrow(new RuntimeException('Redis unavailable'));
        $signals = new RedisGameChangeSignal($factory, app(GameWaitMetrics::class));

        $this->assertNull($signals->cursor(1));
        $this->assertNull($signals->wait(1, '0-0', 100));
    }

    public function test_disabling_notifications_does_not_connect_to_redis(): void
    {
        config(['game-mcp.wait.enabled' => false]);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldNotReceive('connection');
        $signals = new RedisGameChangeSignal($factory, app(GameWaitMetrics::class));
        $signals->publish(1);

        $this->assertNull($signals->cursor(1));
        $this->assertNull($signals->wait(1, '0-0', 100));
    }
}
