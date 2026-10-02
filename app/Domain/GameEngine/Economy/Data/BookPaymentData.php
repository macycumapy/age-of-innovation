<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Economy\Data;

use Spatie\LaravelData\Data;

final class BookPaymentData extends Data
{
    public function __construct(
        public int $banking = 0,
        public int $law = 0,
        public int $engineering = 0,
        public int $medicine = 0,
    ) {
    }

    /** @return array{banking: int, law: int, engineering: int, medicine: int} */
    public function counts(): array
    {
        return [
            'banking' => $this->banking,
            'law' => $this->law,
            'engineering' => $this->engineering,
            'medicine' => $this->medicine,
        ];
    }
}
