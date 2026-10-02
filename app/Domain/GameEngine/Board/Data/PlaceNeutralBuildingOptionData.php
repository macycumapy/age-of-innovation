<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Data;

use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use Spatie\LaravelData\Data;

final class PlaceNeutralBuildingOptionData extends Data implements GameActionOption
{
    public function __construct(
        public string $hexId,
        public BuildingType $buildingType,
    ) {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::PlaceNeutralBuilding;
    }
}
