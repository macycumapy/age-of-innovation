<?php

declare(strict_types=1);

namespace App\Domain\Automation\Enums;

enum StrategicApproach: string
{
    case TownDevelopment = 'town_development';
    case Innovation = 'innovation';
    case Palace = 'palace';
    case Build = 'build';
    case Bridge = 'bridge';
    case Shipping = 'shipping';
}
