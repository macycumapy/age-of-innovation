<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\TerrainType;
use Spatie\LaravelData\Data;

final class PlanningBundleOptionData extends Data implements GameActionOption
{
    public function __construct(public TerrainType $homeland)
    {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::ChoosePlanningBundle;
    }
}
