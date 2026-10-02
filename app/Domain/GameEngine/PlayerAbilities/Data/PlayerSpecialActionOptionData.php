<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\PlayerAbilities\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use Spatie\LaravelData\Data;

final class PlayerSpecialActionOptionData extends Data implements GameActionOption
{
    public function __construct(
        public GameActionOptionType $actionType,
        public ?KnowledgeDiscipline $discipline = null,
    ) {
    }

    public function type(): GameActionOptionType
    {
        return $this->actionType;
    }
}
