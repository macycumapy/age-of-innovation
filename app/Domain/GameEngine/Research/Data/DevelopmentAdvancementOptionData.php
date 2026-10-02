<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Enums\GameActionType;
use Spatie\LaravelData\Data;

final class DevelopmentAdvancementOptionData extends Data implements GameActionOption
{
    public function __construct(
        public GameActionType $action,
        public int $targetLevel,
        public int $tools,
        public int $coins,
        public int $scholars,
    ) {
    }

    public function type(): GameActionOptionType
    {
        return match ($this->action) {
            GameActionType::AdvanceShipping => GameActionOptionType::AdvanceShipping,
            GameActionType::AdvanceTerraforming => GameActionOptionType::AdvanceTerraforming,
            default => throw new \LogicException('Неподдерживаемый тип продвижения.'),
        };
    }
}
