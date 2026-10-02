<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\PlayerAbilities\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
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
