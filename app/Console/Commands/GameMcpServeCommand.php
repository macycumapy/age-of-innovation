<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mcp\Server\AsyncFastCgiBackend;
use App\Mcp\Server\AsyncMcpGateway;
use App\Mcp\Server\AsyncMcpProbe;
use App\Mcp\Server\Services\AsyncGameChangeSignal;
use App\Mcp\Server\Services\GameWaitMetrics;
use App\Mcp\Validators\WaitForGameTurnValidator;
use Clue\React\Redis\Factory;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Reverb\Servers\Reverb\Http\Connection;
use Laravel\Reverb\Servers\Reverb\Http\Router;
use Laravel\Reverb\Servers\Reverb\Http\Server;
use Psr\Http\Message\RequestInterface;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use React\Socket\Connector;
use React\Socket\LimitingServer;
use React\Socket\SocketServer;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

#[Signature('game:mcp-serve {--host=0.0.0.0} {--port=8090} {--backend= : FastCGI backend URI}')]
#[Description('Serve MCP requests with non-blocking Redis waits outside PHP-FPM')]
final class GameMcpServeCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AsyncMcpProbe $probes, GameWaitMetrics $metrics, WaitForGameTurnValidator $validator): int
    {
        $probes->sign('', '', '', 'initial');
        $loop = Loop::get();
        $backend = new AsyncFastCgiBackend(new Connector(['timeout' => 1], $loop), $loop, $probes, (string) ($this->option('backend') ?: config('game-mcp.async.backend')));
        $gateway = new AsyncMcpGateway($backend, new AsyncGameChangeSignal(new Factory($loop)), $metrics, $loop, $validator);
        $routes = new RouteCollection();
        $routes->add('health', new Route('/health', ['_controller' => fn (): string => Message::toString(new Response(200, [], 'ok'))], methods: ['GET']));
        $routes->add('mcp', new Route('/mcp/game', ['_controller' => function (RequestInterface $request, Connection $connection) use ($gateway): PromiseInterface {
            $response = $gateway->handle($request);
            $connection->__call('on', ['close', fn () => $response->cancel()]);

            return $response;
        }], methods: ['POST', 'GET', 'DELETE']));
        $socket = new LimitingServer(new SocketServer($this->option('host').':'.$this->option('port'), [], $loop), 1024);
        $server = new Server($socket, new Router(new UrlMatcher($routes, new RequestContext())), 1024 * 1024, $loop);
        $this->components->info('Async MCP server listening on '.$socket->getAddress());
        foreach ([SIGTERM, SIGINT] as $signal) {
            $loop->addSignal($signal, fn () => $server->stop());
        }
        $server->start();

        return self::SUCCESS;
    }
}
