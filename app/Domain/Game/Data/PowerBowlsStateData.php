<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use Spatie\LaravelData\Data;

/**
 * @property int $bowlOne Количество жетонов силы в чаше I.
 * @property int $bowlTwo Количество жетонов силы в чаше II.
 * @property int $bowlThree Количество жетонов силы в чаше III.
 */
class PowerBowlsStateData extends Data
{
    public function __construct(
        public int $bowlOne = 0,
        public int $bowlTwo = 0,
        public int $bowlThree = 0,
    ) {
    }
}
