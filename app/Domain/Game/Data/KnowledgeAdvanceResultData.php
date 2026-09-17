<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

final readonly class KnowledgeAdvanceResultData
{
    public function __construct(
        public int $advancedSteps,
        public int $gainedPower,
        public int $victoryPoints,
    ) {
    }
}
