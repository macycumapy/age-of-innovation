<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Enums\GameActionOptionType;
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
