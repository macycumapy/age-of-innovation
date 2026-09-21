<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Enums\BookAction;
use App\Domain\Game\Enums\KnowledgeDiscipline;
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

    public function type(): string
    {
        return 'book_action';
    }
}
