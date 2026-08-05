<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum TerrainType: string
{
    case Desert = 'desert';
    case Plains = 'plains';
    case Swamp = 'swamp';
    case Lake = 'lake';
    case Forest = 'forest';
    case Mountain = 'mountain';
    case Wasteland = 'wasteland';
}
