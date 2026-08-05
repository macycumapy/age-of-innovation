<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use Spatie\LaravelData\Data;

/**
 * @property list<BoardHexStateData> $hexes Полное состояние гексов игровой карты.
 * @property list<BridgeStateData> $bridges Построенные игроками мосты.
 */
class BoardStateData extends Data
{
    /**
     * @param list<BoardHexStateData> $hexes
     * @param list<BridgeStateData> $bridges
     */
    public function __construct(
        public array $hexes = [],
        public array $bridges = [],
    ) {
    }
}
