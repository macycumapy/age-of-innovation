<?php

declare(strict_types=1);

namespace App\Domain\Automation\Enums;

enum GameActionSearchLimit: string
{
    case Nodes = 'nodes';
    case Time = 'time';
    case NodesAndTime = 'nodes_and_time';
}
