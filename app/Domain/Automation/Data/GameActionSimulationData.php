<?php

declare(strict_types=1);

namespace App\Domain\Automation\Data;

use App\Domain\GameEngine\State\Data\GameStateData;
use Spatie\LaravelData\Data;

final class GameActionSimulationData extends Data
{
    public function __construct(
        public GameStateData $state,
        public ?int $nextActivePlayerId,
    ) {
    }
}
