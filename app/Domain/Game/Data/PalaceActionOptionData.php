<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PalaceAbility;
use Spatie\LaravelData\Data;

final class PalaceActionOptionData extends Data implements GameActionOption
{
    /** @param list<KnowledgeDiscipline> $knowledgeDisciplines */
    public function __construct(
        public PalaceAbility $palace,
        public ?KnowledgeDiscipline $discipline = null,
        public array $knowledgeDisciplines = [],
        public ?string $hexId = null,
    ) {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::UsePalaceAction;
    }
}
