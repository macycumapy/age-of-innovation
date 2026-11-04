<?php

declare(strict_types=1);

namespace App\Mcp\Server;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\Promise\PromiseInterface;

interface McpBackend
{
    /** @return PromiseInterface<ResponseInterface> */
    public function send(RequestInterface $request, ?string $probe = null): PromiseInterface;
}
