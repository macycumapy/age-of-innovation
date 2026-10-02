<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Interactions\Enums;

enum GameActionAvailabilityReason: string
{
    case InsufficientCoins = 'insufficient_coins';
    case InsufficientTools = 'insufficient_tools';
    case InsufficientScholars = 'insufficient_scholars';
    case InsufficientBooks = 'insufficient_books';
    case InsufficientPower = 'insufficient_power';
    case NoEligibleTarget = 'no_eligible_target';
    case NoReachableTarget = 'no_reachable_target';
    case ActionUnavailable = 'action_unavailable';
    case SupplyLimitReached = 'supply_limit_reached';
    case DevelopmentLimitReached = 'development_limit_reached';
    case SharedActionsUnavailable = 'shared_actions_unavailable';
    case GameSetupUnavailable = 'game_setup_unavailable';
}
