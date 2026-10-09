<?php

declare(strict_types=1);

namespace App\Domain\Automation\Enums;

enum StrategicObjective: string
{
    case FoundTown = 'found_town';
    case MakeInnovation = 'make_innovation';
    case BuildPalace = 'build_palace';
    case ConnectNetwork = 'connect_network';
}
