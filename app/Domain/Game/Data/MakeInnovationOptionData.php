<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\Innovation;
use Spatie\LaravelData\Data;

final class MakeInnovationOptionData extends Data implements GameActionOption
{
    public function __construct(
        public Innovation $innovation,
        public BookPaymentData $payment,
        public int $coins,
        public int $totalBooks,
    ) {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::MakeInnovation;
    }
}
