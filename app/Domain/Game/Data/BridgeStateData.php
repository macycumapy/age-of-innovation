<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use Spatie\LaravelData\Data;

/**
 * @property string $fromHexId Идентификатор первого соединённого гекса.
 * @property string $toHexId Идентификатор второго соединённого гекса.
 * @property int $ownerPlayerId Идентификатор владельца моста.
 */
class BridgeStateData extends Data
{
    public function __construct(
        public string $fromHexId,
        public string $toHexId,
        public int $ownerPlayerId,
    ) {
    }
}
