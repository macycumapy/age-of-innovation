<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class McpWaitLoadTest extends TestCase
{
    private string $directory;

    private string $socket;

    private string $token;

    private string $phase = 'baseline';

    public function test_waiting_requests_fill_the_mcp_pool_and_queue_a_fast_command(): void
    {
        $files = new Filesystem();
        $this->directory = sys_get_temp_dir().'/mcp-wait-load-'.bin2hex(random_bytes(6));
        $this->socket = $this->directory.'/fpm.sock';
        $files->ensureDirectoryExists($this->directory.'/storage/logs');
        $database = $this->directory.'/database.sqlite';
        $files->put($database, '');
        $process = null;
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
            $this->assertTrue(file_exists($this->socket), 'Isolated PHP-FPM did not start: '.$process->getErrorOutput());

            $warmup = $this->request('get_state', ['game_id' => $game->id]);
            $this->response($warmup);
            fclose($warmup);

            $startedAt = hrtime(true);
            $baseline = $this->request('get_state', ['game_id' => $game->id]);
            $this->response($baseline);
            fclose($baseline);
            $baselineMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

            $this->phase = 'waiters';

            for ($i = 0; $i < $workers; $i++) {
                $requests[] = $this->request('wait_for_turn', ['game_id' => $game->id, 'timeout_seconds' => 5]);
            }
            $this->waitForRedisWaiters($workers);

            $startedAt = hrtime(true);
            $this->phase = 'queued';
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
                    } else {
                        $this->assertSame(['reason' => 'timeout'], $payload);
                    }
                    unset($pending[array_search($request, $pending, true)]);
                    fclose($request);
                }
            }
            $this->assertNotNull($queuedMilliseconds);

            $report = [
                'workers' => $workers, 'wait_seconds' => 5,
                'baseline_ms' => round($baselineMilliseconds, 3),
                'queued_ms' => round($queuedMilliseconds, 3),
                'extra_latency_ms' => round($queuedMilliseconds - $baselineMilliseconds, 3),
            ];
            $files->put(storage_path('logs/mcp-wait-load.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
            $this->assertGreaterThan(1000, $queuedMilliseconds - $baselineMilliseconds, 'The diagnostic must observe the current worker-pool saturation.');
        } finally {
            $process?->stop(1);
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
        $connection = stream_socket_client('unix://'.$this->socket, timeout: 1);
        $this->assertIsResource($connection);
        stream_set_timeout($connection, 10);
        $parameters = '';
        foreach ([
            'GATEWAY_INTERFACE' => 'CGI/1.1', 'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SERVER_NAME' => 'localhost', 'SERVER_PORT' => '80', 'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/mcp/game', 'QUERY_STRING' => '',
            'SCRIPT_FILENAME' => public_path('index.php'), 'SCRIPT_NAME' => '/index.php',
            'CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string) strlen($body),
            'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ] as $name => $value) {
            $parameters .= $this->length(strlen($name)).$this->length(strlen($value)).$name.$value;
        }
        $packet = $this->record(1, pack('nC', 1, 0).str_repeat("\0", 5))
            .$this->record(4, $parameters).$this->record(4, '')
            .$this->record(5, $body).$this->record(5, '');
        $this->assertSame(strlen($packet), fwrite($connection, $packet));

        return $connection;
    }

    private function length(int $length): string
    {
        return $length < 128 ? chr($length) : pack('N', $length | 0x80000000);
    }

    private function record(int $type, string $content): string
    {
        return pack('CCnnCC', 1, $type, 1, strlen($content), 0, 0).$content;
    }

    /**
     * @param resource $connection
     * @return array<string, mixed>
     */
    private function response(mixed $connection): array
    {
        $output = '';
        do {
            $header = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', $this->read($connection, 8));
            $body = $this->read($connection, $header['length']);
            $this->read($connection, $header['padding']);
            if ($header['type'] === 6) {
                $output .= $body;
            }
        } while ($header['type'] !== 3);

        $parts = preg_split('/\r?\n\r?\n/', $output, 2);
        $this->assertCount(2, $parts);
        $response = json_decode($parts[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($response['result']['isError'] ?? true, $parts[1]);

        return $response['result']['structuredContent'];
    }

    /** @param resource $connection */
    private function read(mixed $connection, int $length): string
    {
        $content = '';
        while (strlen($content) < $length) {
            $chunk = fread($connection, $length - strlen($content));
            if ($chunk === false) {
                $logs = file_get_contents($this->directory.'/fpm.log');
                foreach (glob($this->directory.'/storage/logs/mcp-*.log') as $file) {
                    $logs .= file_get_contents($file);
                }
                $this->fail('FastCGI read failed during '.$this->phase.' for '.$length.' bytes. '.$logs);
            }
            $this->assertNotSame('', $chunk, 'The isolated PHP-FPM response ended before the request completed.');
            $content .= $chunk;
        }

        return $content;
    }
}
