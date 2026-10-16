<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

final readonly class GameTurnWaitResultData
{
    public function __construct(public string $reason, public GameStateViewData $view)
    {
    }
}
