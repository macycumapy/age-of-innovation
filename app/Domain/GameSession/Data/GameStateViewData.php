<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Data;

use App\Domain\GameEngine\State\Data\GameStateData;

final readonly class GameStateViewData
{
    public function __construct(
        public int $gameId,
        public int $userId,
        public int $playerId,
        public int $version,
        public ?int $activePlayerId,
        public GameStateData $state,
        public ?StagedGameActionData $stagedAction,
    ) {
    }
}
