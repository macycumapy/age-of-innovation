<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\MapVariant;
use Spatie\LaravelData\Data;

/**
 * @property MapVariant $variant Сторона игрового поля, выбранная по числу игроков.
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
        public MapVariant $variant = MapVariant::ThreeToFivePlayers,
        public array $hexes = [],
        public array $bridges = [],
    ) {
    }
}
