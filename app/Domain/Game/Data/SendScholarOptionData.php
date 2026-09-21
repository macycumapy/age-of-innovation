<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use Spatie\LaravelData\Data;

final class SendScholarOptionData extends Data implements GameActionOption
{
    public function __construct(
        public KnowledgeDiscipline $discipline,
        public bool $place,
        public int $steps,
        public ?int $slotIndex,
    ) {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::SendScholar;
    }
}
