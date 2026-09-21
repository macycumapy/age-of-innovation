<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum GameActionOptionType: string
{
    case BookAction = 'book_action';
    case PowerAction = 'power_action';
    case BuildWorkshop = 'build_workshop';
    case UpgradeBuilding = 'upgrade_building';
    case PaidTerraforming = 'paid_terraforming';
    case AdvanceShipping = 'advance_shipping';
    case AdvanceTerraforming = 'advance_terraforming';
    case SendScholar = 'send_scholar';
    case MakeInnovation = 'make_innovation';
    case Pass = 'pass';
    case ChooseRoundBonus = 'choose_round_bonus';
    case UseInnovationAction = 'use_innovation_action';
    case UsePalaceAction = 'use_palace_action';
}
