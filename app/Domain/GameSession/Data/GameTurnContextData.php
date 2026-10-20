<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Data;

final readonly class GameTurnContextData
{
    public function __construct(public GameStateViewData $view, public GameActionOptionsData $actions)
    {
    }
}
