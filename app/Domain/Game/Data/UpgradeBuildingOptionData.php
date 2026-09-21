<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GameActionOptionType;
use Spatie\LaravelData\Data;

final class UpgradeBuildingOptionData extends Data implements GameActionOption
{
    public function __construct(
        public string $hexId,
        public BuildingType $source,
        public BuildingType $target,
        public int $tools,
        public int $coins,
    ) {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::UpgradeBuilding;
    }
}
