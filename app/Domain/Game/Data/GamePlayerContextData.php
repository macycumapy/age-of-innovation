<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Models\Game;
use App\Models\GamePlayer;

final readonly class GamePlayerContextData
{
    public function __construct(public Game $game, public GamePlayer $player)
    {
    }
}
