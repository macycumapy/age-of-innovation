<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Economy\Data\BookPaymentData;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Research\Enums\Innovation;
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
