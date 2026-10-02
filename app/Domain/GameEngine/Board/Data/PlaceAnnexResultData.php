<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Data;

use Spatie\LaravelData\Data;

final class PlaceAnnexResultData extends Data
{
    public function __construct(public int $nextActivePlayerId)
    {
    }
}
