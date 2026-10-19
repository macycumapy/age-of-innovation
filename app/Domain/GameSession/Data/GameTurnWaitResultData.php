<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Data;

use App\Domain\GameSession\Enums\GameTurnWaitReason;

final readonly class GameTurnWaitResultData
{
    public function __construct(public GameTurnWaitReason $reason, public int $gameId, public ?GameStateViewData $view = null)
    {
    }
}
