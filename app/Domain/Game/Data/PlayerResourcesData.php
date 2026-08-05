<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use Spatie\LaravelData\Data;

/**
 * @property int $coins Количество монет игрока.
 * @property int $tools Количество инструментов игрока.
 * @property int $scholars Количество доступных учёных игрока.
 * @property BookSupplyData $books Книги игрока по дисциплинам.
 * @property PowerBowlsStateData $power Распределение жетонов по чашам силы.
 */
class PlayerResourcesData extends Data
{
    public function __construct(
        public int $coins = 0,
        public int $tools = 0,
        public int $scholars = 0,
        public BookSupplyData $books = new BookSupplyData(),
        public PowerBowlsStateData $power = new PowerBowlsStateData(),
    ) {
    }
}
