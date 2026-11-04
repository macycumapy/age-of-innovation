<?php

declare(strict_types=1);

namespace App\Mcp\Data;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;

final class FastCgiRequestData
{
    public ?ConnectionInterface $connection = null;

    public ?TimerInterface $timer = null;

    /** @var PromiseInterface<ConnectionInterface>|null */
    public ?PromiseInterface $connecting = null;

    public bool $finished = false;

    public string $buffer = '';

    public string $output = '';

    public function close(LoopInterface $loop): void
    {
        $this->finished = true;
        if ($this->timer !== null) {
            $loop->cancelTimer($this->timer);
            $this->timer = null;
        }
        $connecting = $this->connecting;
        $connection = $this->connection;
        $this->connecting = $this->connection = null;
        $connecting?->cancel();
        $connection?->close();
        $this->buffer = $this->output = '';
    }
}
