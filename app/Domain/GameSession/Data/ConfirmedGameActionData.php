<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Data;

use App\Domain\GameSession\Enums\GameActionContinuation;
use App\Models\Game;

final readonly class ConfirmedGameActionData
{
    public function __construct(
        public Game $game,
        public StagedGameActionData $action,
        public GameActionContinuation $continuation,
        public ?int $nextPlayerId,
    ) {
    }
}
