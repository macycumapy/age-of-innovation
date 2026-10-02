<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\GameActionOptionType;

final class GameActionSimulationTimingsData
{
    public int $calls = 0;

    public int $nanoseconds = 0;

    public int $stateCopyNanoseconds = 0;

    public int $executionNanoseconds = 0;

    public int $maximumNanoseconds = 0;

    public function __construct(public readonly GameActionOptionType $type)
    {
    }
}
