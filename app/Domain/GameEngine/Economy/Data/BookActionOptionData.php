<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Economy\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Economy\Enums\BookAction;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use Spatie\LaravelData\Data;

final class BookActionOptionData extends Data implements GameActionOption
{
    public function __construct(
        public BookAction $action,
        public BookPaymentData $payment,
        public ?KnowledgeDiscipline $discipline = null,
        public ?string $hexId = null,
    ) {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::BookAction;
    }
}
