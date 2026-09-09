<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum GameActionType: string
{
    case PhaseCheckpoint = 'phase_checkpoint';
    case IncomePhase = 'income_phase';
    case ScienceBonusPhase = 'science_bonus_phase';
    case StartGame = 'start_game';
    case ChoosePlanningBundle = 'choose_planning_bundle';
    case ChooseStartingResources = 'choose_starting_resources';
    case ChooseIncomeResources = 'choose_income_resources';
    case PlaceStartingBuilding = 'place_starting_building';
    case UndoStartingBuilding = 'undo_starting_building';
    case FinishStartingBuildingTurn = 'finish_starting_building_turn';
    case SpendStartingSpade = 'spend_starting_spade';
    case TerraformAndBuild = 'terraform_and_build';
    case BuildWorkshop = 'build_workshop';
    case FinishTurn = 'finish_turn';
    case UpgradeBuilding = 'upgrade_building';
    case AdvanceShipping = 'advance_shipping';
    case AdvanceTerraforming = 'advance_terraforming';
    case MakeInnovation = 'make_innovation';
    case SendScholar = 'send_scholar';
    case PowerAction = 'power_action';
    case SacrificePower = 'sacrifice_power';
    case BookAction = 'book_action';
    case SpecialAction = 'special_action';
    case ExchangeResources = 'exchange_resources';
    case Pass = 'pass';
    case ChooseRoundBonus = 'choose_round_bonus';
    case ChooseScienceBonusBooks = 'choose_science_bonus_books';
    case AcceptPower = 'accept_power';
    case DeclinePower = 'decline_power';
    case ChooseTown = 'choose_town';
    case ChooseTownBooks = 'choose_town_books';
    case ChooseFelineTownBonus = 'choose_feline_town_bonus';
    case AcceptPalaceWaterTown = 'accept_palace_water_town';
    case DeclinePalaceWaterTown = 'decline_palace_water_town';
    case ChoosePalace = 'choose_palace';
    case PlacePalaceGuild = 'place_palace_guild';
    case PlaceAnnex = 'place_annex';
    case ChooseCompetency = 'choose_competency';
}
