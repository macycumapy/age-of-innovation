<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use Spatie\LaravelData\Data;

/**
 * @property int $banking Количество книг банковского дела.
 * @property int $law Количество книг права.
 * @property int $engineering Количество книг инженерного дела.
 * @property int $medicine Количество книг медицины.
 */
class BookSupplyData extends Data
{
    public function __construct(
        public int $banking = 0,
        public int $law = 0,
        public int $engineering = 0,
        public int $medicine = 0,
    ) {
    }
}
