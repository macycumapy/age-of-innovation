<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Contracts\GameActionOption;
use Spatie\LaravelData\Data;

final class PaidTerraformingOptionData extends Data implements GameActionOption
{
    public function __construct(
        public string $hexId,
        public bool $useAvailable,
        public bool $useTunnel,
        public bool $useFlight,
        public int $toolCost,
        public int $scholarCost,
        public int $spadeCount,
    ) {
    }

    public function type(): string
    {
        return 'paid_terraforming';
    }
}
