<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\KnowledgeDiscipline;
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
