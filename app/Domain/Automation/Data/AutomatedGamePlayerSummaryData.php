<?php

declare(strict_types=1);

namespace App\Domain\Automation\Data;

final readonly class AutomatedGamePlayerSummaryData
{
    public function __construct(
        public int $playerId,
        public int $towns,
        public int $shippingLevel,
        public int $terraformingLevel,
        public int $largestNetwork,
        public ?AutomatedGameResourcesData $resourcesAtLastPass,
    ) {
    }
}
