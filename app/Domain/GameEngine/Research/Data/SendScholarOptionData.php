<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
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
