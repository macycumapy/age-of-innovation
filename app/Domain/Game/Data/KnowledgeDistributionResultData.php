<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use Spatie\LaravelData\Data;

final class KnowledgeDistributionResultData extends Data
{
    public function __construct(
        public int $victoryPoints,
        public int $gainedPower,
    ) {
    }
}
