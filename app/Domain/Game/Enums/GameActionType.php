<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum GameActionType: string
{
    case TerraformAndBuild = 'terraform_and_build';
    case UpgradeBuilding = 'upgrade_building';
    case AdvanceShipping = 'advance_shipping';
    case AdvanceTerraforming = 'advance_terraforming';
    case MakeInnovation = 'make_innovation';
    case SendScholar = 'send_scholar';
    case PowerAction = 'power_action';
    case BookAction = 'book_action';
    case SpecialAction = 'special_action';
    case ExchangeResources = 'exchange_resources';
    case Pass = 'pass';
    case AcceptPower = 'accept_power';
    case DeclinePower = 'decline_power';
    case ChooseTown = 'choose_town';
    case ChoosePalace = 'choose_palace';
    case ChooseCompetency = 'choose_competency';
}
