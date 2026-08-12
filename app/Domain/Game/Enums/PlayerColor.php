<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum PlayerColor: string
{
    case Yellow = 'yellow';
    case Red = 'red';
    case Black = 'black';
    case Blue = 'blue';
    case Green = 'green';
    case Brown = 'brown';
    case Grey = 'grey';
}
