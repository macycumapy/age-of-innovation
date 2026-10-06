<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\PlayerAbilities\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use Spatie\LaravelData\Data;

final class ChoosePalaceRewardOrderOptionData extends Data implements GameActionOption
{
    public function __construct(public string $firstReward)
    {
    }
    public function type(): GameActionOptionType
    {
        return GameActionOptionType::ChoosePalaceRewardOrder;
    }
}
