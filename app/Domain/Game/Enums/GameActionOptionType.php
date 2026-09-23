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
    case UseFactionAction = 'use_faction_action';
    case UseCompetencyAction = 'use_competency_action';
    case UseRoundBonusAction = 'use_round_bonus_action';
    case ExchangeResources = 'exchange_resources';
    case SacrificePower = 'sacrifice_power';
    case PlaceAnnex = 'place_annex';
    case ResolvePowerOffer = 'resolve_power_offer';
    case ChooseTown = 'choose_town';
    case ResolveWorkshopAfterTerraforming = 'resolve_workshop_after_terraforming';
    case ResolvePalaceWaterTown = 'resolve_palace_water_town';
    case ChoosePalace = 'choose_palace';
    case ChooseCompetency = 'choose_competency';
    case PlaceNeutralBuilding = 'place_neutral_building';
    case PlaceBridge = 'place_bridge';
    case SpendSpades = 'spend_spades';
    case PlacePalaceGuild = 'place_palace_guild';
    case DistributeRewards = 'distribute_rewards';
}
