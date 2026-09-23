<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum GameEventType: string
{
    case AnnexPlaced = 'annex_placed';
    case BookActionUsed = 'book_action_used';
    case BridgeBuilt = 'bridge_built';
    case BuildingCompetencyChosen = 'building_competency_chosen';
    case BuildingUpgraded = 'building_upgraded';
    case CompetencyActionUsed = 'competency_action_used';
    case FactionActionUsed = 'faction_action_used';
    case FelineTownBonusChosen = 'feline_town_bonus_chosen';
    case GameStarted = 'game_started';
    case GoblinSpadeBonusReceived = 'goblin_spade_bonus_received';
    case IncomePhaseResolved = 'income_phase_resolved';
    case IncomePhaseStarted = 'income_phase_started';
    case IncomeResourcesChosen = 'income_resources_chosen';
    case InnovationActionUsed = 'innovation_action_used';
    case InnovationCompetencyChosen = 'innovation_competency_chosen';
    case InnovationCreated = 'innovation_created';
    case InnovationRewardDistributed = 'innovation_reward_distributed';
    case MoleTunnelUsed = 'mole_tunnel_used';
    case NeutralBuildingBuilt = 'neutral_building_built';
    case PalaceActionUsed = 'palace_action_used';
    case PalaceBooksChosen = 'palace_books_chosen';
    case PalaceChosen = 'palace_chosen';
    case PalaceFlightUsed = 'palace_flight_used';
    case PalaceGuildPlaced = 'palace_guild_placed';
    case PalaceWaterTownAccepted = 'palace_water_town_accepted';
    case PalaceWaterTownDeclined = 'palace_water_town_declined';
    case PhaseStarted = 'phase_started';
    case PlanningBundleChosen = 'planning_bundle_chosen';
    case PlayerPassed = 'player_passed';
    case PowerAccepted = 'power_accepted';
    case PowerActionUsed = 'power_action_used';
    case PowerDeclined = 'power_declined';
    case PowerSacrificed = 'power_sacrificed';
    case ResourcesExchanged = 'resources_exchanged';
    case RoundBonusActionUsed = 'round_bonus_action_used';
    case RoundBonusChosen = 'round_bonus_chosen';
    case RoundSpadeScored = 'round_spade_scored';
    case ScholarSent = 'scholar_sent';
    case ScienceBonusBooksChosen = 'science_bonus_books_chosen';
    case ScienceBonusPhaseResolved = 'science_bonus_phase_resolved';
    case ShippingAdvanced = 'shipping_advanced';
    case ShippingBooksChosen = 'shipping_books_chosen';
    case SpadeSpent = 'spade_spent';
    case StartingBuildingPlaced = 'starting_building_placed';
    case StartingCompetencyChosen = 'starting_competency_chosen';
    case StartingResourcesChosen = 'starting_resources_chosen';
    case StartingSpadeSpent = 'starting_spade_spent';
    case TerraformingAdvanced = 'terraforming_advanced';
    case TerraformingBooksChosen = 'terraforming_books_chosen';
    case TownBooksChosen = 'town_books_chosen';
    case TownFounded = 'town_founded';
    case TurnFinished = 'turn_finished';
    case WorkshopBuilt = 'workshop_built';
    case WorkshopBuiltAfterTerraforming = 'workshop_built_after_terraforming';
    case WorkshopDeclinedAfterTerraforming = 'workshop_declined_after_terraforming';
}
