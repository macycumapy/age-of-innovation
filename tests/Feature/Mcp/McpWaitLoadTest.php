<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Server\Services\GameWaitMetrics;
use App\Mcp\Server\Services\RedisGameChangeSignal;
use App\Models\Game;
use App\Models\GamePlayer;
use GuzzleHttp\Psr7\Message;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class McpWaitLoadTest extends TestCase
{
    private string $directory;

    private string $socket;

    private string $token;

    private string $gatewayAddress;

    public function test_async_waiting_requests_leave_the_mcp_pool_available_for_a_fast_command(): void
    {
        $files = new Filesystem();
        $this->directory = sys_get_temp_dir().'/mcp-wait-load-'.bin2hex(random_bytes(6));
        $this->socket = $this->directory.'/fpm.sock';
        $files->ensureDirectoryExists($this->directory.'/storage/logs');
        $database = $this->directory.'/database.sqlite';
        $files->put($database, '');
        $process = null;
        $gatewayProcess = null;
        $signalRedis = null;
        $requests = [];

        try {
            config([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => $database,
                'database.connections.sqlite.journal_mode' => 'WAL',
                'database.connections.sqlite.busy_timeout' => 5000,
            ]);
            DB::purge('sqlite');
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true, '--no-interaction' => true]));
            $game = Game::factory()->active()->create();
            $player = GamePlayer::factory()->for($game)->create();
            $this->token = $player->user->createToken('load-test', ['game:mcp'])->plainTextToken;
            $pool = parse_ini_file(base_path('docker/php/8.5/www.conf'), true, INI_SCANNER_TYPED)['mcp'];
            $workers = $pool['pm.max_children'];
            $this->assertGreaterThan(0, $workers);
            $configuration = $this->directory.'/fpm.conf';
            $files->put($configuration, "[global]\ndaemonize = no\nerror_log = {$this->directory}/fpm.log\n[mcp]\n"
                .'user = '.posix_geteuid()."\ngroup = ".posix_getegid()."\nlisten = {$this->socket}\npm = static\n"
                ."pm.max_children = {$workers}\nclear_env = no\ncatch_workers_output = yes\n");
            $process = new Process(['/usr/sbin/php-fpm'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, '--nodaemonize', '--allow-to-run-as-root', '--fpm-config', $configuration], base_path(), [
                'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'),
                'APP_CONFIG_CACHE' => $this->directory.'/config.php',
                'APP_ROUTES_CACHE' => $this->directory.'/routes.php',
                'LARAVEL_STORAGE_PATH' => $this->directory.'/storage',
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null',
                'REDIS_PREFIX' => 'test:mcp-wait-load:'.bin2hex(random_bytes(8)).':',
                'GAME_MCP_WAIT_ENABLED' => 'true', 'GAME_MCP_METRICS_ENABLED' => 'true',
            ]);
            $process->start();
            $deadline = microtime(true) + 5;
            while (! file_exists($this->socket) && microtime(true) < $deadline && $process->isRunning()) {
                usleep(10000);
            }
            $this->assertTrue(file_exists($this->socket), 'Isolated PHP-FPM did not start: '.$process->getErrorOutput().(is_file($this->directory.'/fpm.log') ? file_get_contents($this->directory.'/fpm.log') : ''));

            $reservation = stream_socket_server('tcp://127.0.0.1:0');
            $this->gatewayAddress = stream_socket_get_name($reservation, false);
            fclose($reservation);
            $port = explode(':', $this->gatewayAddress)[1];
            $gatewayProcess = new Process([PHP_BINARY, 'artisan', 'game:mcp-serve', '--host=127.0.0.1', '--port='.$port, '--backend=unix://'.$this->socket], base_path(), $process->getEnv());
            $gatewayProcess->start();
            $deadline = microtime(true) + 5;
            while (! str_contains($gatewayProcess->getOutput(), 'Async MCP server listening') && microtime(true) < $deadline && $gatewayProcess->isRunning()) {
                usleep(10000);
            }
            $this->assertTrue(str_contains($gatewayProcess->getOutput(), 'Async MCP server listening'), $gatewayProcess->getErrorOutput().$gatewayProcess->getOutput());

            $warmup = $this->request('get_state', ['game_id' => $game->id]);
            $this->response($warmup);
            fclose($warmup);

            $startedAt = hrtime(true);
            $baseline = $this->request('get_state', ['game_id' => $game->id]);
            $this->response($baseline);
            fclose($baseline);
            $baselineMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

            for ($i = 0; $i < $workers; $i++) {
                $requests[] = $this->request('wait_for_turn', ['game_id' => $game->id, 'timeout_seconds' => 5]);
            }
            $this->waitForRedisWaiters($workers);

            $startedAt = hrtime(true);
            $queued = $this->request('get_state', ['game_id' => $game->id]);
            $requests[] = $queued;
            $pending = $requests;
            $queuedMilliseconds = null;
            /** Drain completed FastCGI connections so workers can accept queued requests. */
            while ($pending !== []) {
                $readable = array_values($pending);
                $writable = $exceptional = [];
                $this->assertGreaterThan(0, stream_select($readable, $writable, $exceptional, 10), 'PHP-FPM responses did not complete.');
                foreach ($readable as $request) {
                    $payload = $this->response($request);
                    if ($request === $queued) {
                        $queuedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;
                        $this->assertCount($workers + 1, $pending, 'The fast request must finish before any wait times out.');
                    } else {
                        $this->assertSame(['reason' => 'timeout'], $payload);
                    }
                    unset($pending[array_search($request, $pending, true)]);
                    fclose($request);
                }
            }
            $this->assertNotNull($queuedMilliseconds);

            $wake = $this->request('wait_for_turn', ['game_id' => $game->id, 'timeout_seconds' => 5]);
            $requests[] = $wake;
            $this->waitForRedisWaiters($workers + 1);
            $game->update(['active_game_player_id' => $player->id, 'version' => 2]);
            $redisConfig = config('database.redis');
            $redisConfig['options']['prefix'] = $process->getEnv()['REDIS_PREFIX'];
            $signalRedis = new RedisManager($this->app, $redisConfig['client'], $redisConfig);
            config(['game-mcp.wait.enabled' => true]);
            $startedAt = hrtime(true);
            (new RedisGameChangeSignal($signalRedis, app(GameWaitMetrics::class)))->publish($game->id);
            $context = $this->response($wake);
            $wakeMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;
            fclose($wake);
            $this->assertSame('your_turn', $context['reason']);
            $this->assertSame(2, $context['version']);
            $this->assertArrayHasKey('actions', $context);
            $this->assertArrayHasKey('context_id', $context);
            $this->assertLessThan(1000, $wakeMilliseconds);

            $report = [
                'workers' => $workers, 'wait_seconds' => 5,
                'baseline_ms' => round($baselineMilliseconds, 3),
                'concurrent_ms' => round($queuedMilliseconds, 3),
                'extra_latency_ms' => round($queuedMilliseconds - $baselineMilliseconds, 3),
                'wake_ms' => round($wakeMilliseconds, 3),
            ];
            $files->put(storage_path('logs/mcp-wait-load.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
            $this->assertLessThan(1000, $queuedMilliseconds, 'Waiting clients must leave the MCP backend available.');
        } finally {
            $gatewayProcess?->stop(1);
            $process?->stop(1);
            if ($signalRedis !== null) {
                $signalRedis->connection('game_signals')->del('game:turn-signals:'.$game->id);
                $signalRedis->purge('game_signals');
            }
            foreach ($requests as $request) {
                if (is_resource($request)) {
                    fclose($request);
                }
            }
            DB::purge('sqlite');
            $files->deleteDirectory($this->directory);
        }
    }

    private function waitForRedisWaiters(int $workers): void
    {
        $deadline = microtime(true) + 3;
        do {
            $records = [];
            foreach (glob($this->directory.'/storage/logs/mcp-*.log') as $file) {
                foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $record = json_decode($line, true);
                    if (($record['message'] ?? null) === 'mcp.wait_transport') {
                        $records[] = $record['context'];
                    }
                }
            }
            if (count($records) === $workers) {
                $this->assertSame(array_fill(0, $workers, 'redis'), array_column($records, 'transport'));

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail('The isolated PHP-FPM pool did not start all Redis waits.');
    }

    /**
     * @param array<string, int> $arguments
     * @return resource
     */
    private function request(string $tool, array $arguments): mixed
    {
        $body = json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ], JSON_THROW_ON_ERROR);
        $connection = stream_socket_client('tcp://'.$this->gatewayAddress, timeout: 1);
        $this->assertIsResource($connection);
        stream_set_timeout($connection, 10);
        $packet = "POST /mcp/game HTTP/1.0\r\nHost: localhost\r\nConnection: close\r\nContent-Type: application/json\r\nAccept: application/json\r\nAuthorization: Bearer {$this->token}\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body;
        $this->assertSame(strlen($packet), fwrite($connection, $packet));

        return $connection;
    }

    /**
     * @param resource $connection
     * @return array<string, mixed>
     */
    private function response(mixed $connection): array
    {
        $raw = stream_get_contents($connection);
        $this->assertNotFalse($raw);
        $response = Message::parseResponse($raw);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $response = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($response['result']['isError'] ?? true);

        return $response['result']['structuredContent'];
    }

}
