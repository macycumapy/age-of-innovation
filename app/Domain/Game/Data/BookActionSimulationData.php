<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use Spatie\LaravelData\Data;

final class BookActionSimulationData extends Data
{
    public function __construct(
        public GameStateData $state,
        public BookActionResultData $result,
    ) {
    }
}
