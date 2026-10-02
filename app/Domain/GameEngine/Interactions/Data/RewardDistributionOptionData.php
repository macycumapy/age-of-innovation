<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Interactions\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use Spatie\LaravelData\Data;

final class RewardDistributionOptionData extends Data implements GameActionOption
{
    /**
     * @param array<string, int> $bookCounts
     * @param array<string, int> $knowledgeCounts
     */
    public function __construct(
        public array $bookCounts,
        public array $knowledgeCounts = [],
    ) {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::DistributeRewards;
    }
}
