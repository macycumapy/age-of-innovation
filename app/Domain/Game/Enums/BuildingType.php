<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum BuildingType: string
{
    case Workshop = 'workshop';
    case Guild = 'guild';
    case School = 'school';
    case University = 'university';
    case Palace = 'palace';
    case Tower = 'tower';
    case Monument = 'monument';
}
