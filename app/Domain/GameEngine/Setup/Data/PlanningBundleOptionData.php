<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Setup\Data;

use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;
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
